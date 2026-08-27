<?php

declare(strict_types=1);

namespace Tlon\App;

require __DIR__ . '/../src/core/state.php';
require __DIR__ . '/../src/core/events.php';
require __DIR__ . '/../src/core/queries.php';
require __DIR__ . '/../src/core/step.php';
require __DIR__ . '/../src/shell/clock.php';
require __DIR__ . '/../src/shell/store.php';
require __DIR__ . '/../src/app/render.php';

use Tlon\Core\AddNote;

use function Tlon\Core\{absent_tables, change_report, column_count, columns_of, current_inspection,
    find_source_by_name, indexes_of, last_seen_table, note_subject_absent, primary_key_of,
    referencing_tables, search_matches, source_notes, tables_of, unavailable_of};
use function Tlon\Shell\{load_state, next_id, now, save_state, state_path};

$state = load_state(state_path());
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$segments = array_values(array_filter(explode('/', $path)));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $source = find_source_by_name($state, $_POST['source'] ?? '');
    $result = \Tlon\Core\step($state, new AddNote(
        next_id($state, 'notes', 'n'),
        $source?->id ?? '',
        $_POST['table'] ?? '',
        $_POST['column'] ?? '',
        $_POST['body'] ?? '',
    ), now());
    save_state($result->state, state_path());
    header('Location: ' . ($_POST['return'] ?? '/'));
    exit;
}

echo match ($segments[0] ?? '') {
    '' => page_sources($state),
    'source' => isset($segments[2]) ? page_table($state, $segments[1], $segments[2]) : page_source($state, $segments[1] ?? ''),
    'changes' => page_changes($state, $segments[1] ?? ''),
    'search' => page_search($state, $_GET['q'] ?? ''),
    default => page_missing(),
};

function page_sources(\Tlon\Core\State $state): string
{
    $rows = [];
    foreach ($state->sources as $source) {
        $current = current_inspection($state, $source->id);
        $rows[] = [
            '<strong><a href="/source/' . rawurlencode($source->name) . '">' . e($source->name) . '</a></strong>',
            '<span class="tag">' . e($source->engine) . '</span>',
            [count(tables_of($state, $current))],
            e($current?->endedAt ?? 'never inspected'),
        ];
    }

    return layout('sources', '<h1>Sources</h1><p class="sub">Registered relational databases and what tlon last saw in them.</p>'
        . table(['name', 'engine', 'tables', 'last inspected'], $rows, 'No sources registered. Use ./tlon register.')
        . '<h2>Search</h2><form method="get" action="/search"><input name="q" placeholder="table or column name" autofocus><button>Search</button></form>');
}

function page_source(\Tlon\Core\State $state, string $name): string
{
    $source = find_source_by_name($state, $name);
    if ($source === null) {
        return page_missing();
    }
    $current = current_inspection($state, $source->id);
    $rows = [];
    foreach (tables_of($state, $current) as $table) {
        $rows[] = [
            '<strong><a href="/source/' . rawurlencode($name) . '/' . rawurlencode($table->name) . '">' . e($table->name) . '</a></strong>',
            '<span class="tag">' . e($table->kind) . '</span>',
            [column_count($state, $current->id, $table->name)],
            e(implode(', ', primary_key_of($state, $current, $table->name))),
            e(last_seen_table($state, $source->id, $table->name)?->endedAt ?? ''),
        ];
    }
    $absent = array_map(fn (string $n) => [e($n), '<span class="tag absent">absent</span>'], absent_tables($state, $source->id));

    return layout($name, '<h1>' . e($name) . '</h1><p class="sub">' . e($source->engine)
        . ' · <a href="/changes/' . rawurlencode($name) . '">what changed</a></p>'
        . table(['table', 'kind', 'columns', 'primary key', 'last seen'], $rows, 'Not inspected yet.')
        . '<h2>Absent</h2>' . table(['table', ''], $absent, 'Nothing has gone missing.')
        . '<h2>Notes</h2>' . notes_table($state, $source->id), [$name => '']);
}

function page_table(\Tlon\Core\State $state, string $name, string $tableName): string
{
    $source = find_source_by_name($state, $name);
    if ($source === null) {
        return page_missing();
    }
    $current = current_inspection($state, $source->id);
    $rows = [];
    foreach (columns_of($state, $current, $tableName) as $column) {
        $rows[] = [
            [$column->position],
            '<strong>' . e($column->name) . '</strong>',
            e($column->declaredType),
            $column->acceptsNothing ? 'null' : '<span class="tag">not null</span>',
            e($column->hasDefault ? $column->defaultExpression : ''),
        ];
    }
    $refs = array_map(fn (array $r) => [e($r['to']), e(implode(', ', array_column($r['columns'], 'from'))), e($r['onDelete'])],
        \Tlon\Core\references_of($state, $current, $tableName));
    $back = array_map(fn ($r) => [e($r->fromTable), e($r->name), e($r->onDelete)], referencing_tables($state, $source->id, $tableName));
    $idx = array_map(fn (array $i) => [e($i['name']), e(implode(', ', $i['columns'])), $i['unique'] ? '<span class="tag">unique</span>' : ''],
        indexes_of($state, $current, $tableName));
    $missing = unavailable_of($state, $current, $tableName);

    return layout($tableName, '<h1>' . e($tableName) . '</h1><p class="sub">in ' . e($name) . '</p>'
        . table(['#', 'column', 'type', 'nullable', 'default'], $rows, 'No columns observed.')
        . '<h2>References out</h2>' . table(['to', 'columns', 'on delete'], $refs, 'This table references nothing.')
        . '<h2>Referenced by</h2>' . table(['from', 'reference', 'on delete'], $back, 'Nothing references this table.')
        . '<h2>Indexes</h2>' . table(['name', 'columns', ''], $idx, 'No indexes.')
        . '<h2>Not supplied by the engine</h2>' . table(['datum'], array_map(fn ($d) => [e($d)], $missing), 'The engine supplied everything.')
        . '<h2>Add a note</h2><form method="post" action="/">'
        . '<input type="hidden" name="source" value="' . e($name) . '">'
        . '<input type="hidden" name="table" value="' . e($tableName) . '">'
        . '<input type="hidden" name="return" value="/source/' . e(rawurlencode($name)) . '">'
        . '<input name="column" placeholder="column (optional)"><input name="body" placeholder="what you want to remember" size="40"><button>Save</button></form>',
        [$name => '/source/' . rawurlencode($name), $tableName => '']);
}

function notes_table(\Tlon\Core\State $state, string $sourceId): string
{
    $rows = [];
    foreach (source_notes($state, $sourceId) as $note) {
        $rows[] = [
            e($note->tableName),
            e($note->columnName),
            note_subject_absent($state, $note->id) ? '<span class="tag absent">absent</span>' : '<span class="tag">present</span>',
            e($note->body),
        ];
    }

    return table(['table', 'column', 'subject', 'note'], $rows, 'No notes yet.');
}

function page_changes(\Tlon\Core\State $state, string $name): string
{
    $source = find_source_by_name($state, $name);
    if ($source === null) {
        return page_missing();
    }
    $report = change_report($state, $source->id);
    $changed = [];
    foreach ($report['changed'] as $table => $detail) {
        foreach ($detail['columns'] as $column => $what) {
            $changed[] = [e($table), e($column), e(json_encode($what))];
        }
    }

    return layout('changes in ' . $name, '<h1>What changed</h1><p class="sub">in ' . e($name) . ', since the previous completed inspection</p>'
        . '<h2>Added</h2>' . table(['table'], array_map(fn ($t) => [e($t)], $report['added']), 'Nothing new.')
        . '<h2>Absent</h2>' . table(['table'], array_map(fn ($t) => [e($t)], $report['absent']), 'Nothing went missing.')
        . '<h2>Changed columns</h2>' . table(['table', 'column', 'change'], $changed, 'No columns changed.'),
        [$name => '/source/' . rawurlencode($name), 'changes' => '']);
}

function page_search(\Tlon\Core\State $state, string $term): string
{
    $rows = array_map(fn (array $m) => [
        '<a href="/source/' . rawurlencode($m['source']) . '">' . e($m['source']) . '</a>',
        '<a href="/source/' . rawurlencode($m['source']) . '/' . rawurlencode($m['table']) . '">' . e($m['table']) . '</a>',
        e($m['column']),
    ], search_matches($state, $term));

    return layout('search', '<h1>Search</h1><form method="get" action="/search"><input name="q" value="' . e($term) . '" autofocus><button>Search</button></form><h2>Matches</h2>'
        . table(['source', 'table', 'column'], $rows, 'Nothing matched.'), ['search' => '']);
}

function page_missing(): string
{
    http_response_code(404);

    return layout('not found', '<h1>Not found</h1><p class="sub">No such source or table.</p>');
}
