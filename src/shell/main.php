<?php

declare(strict_types=1);

namespace Tlon\Shell;

require __DIR__ . '/../core/state.php';
require __DIR__ . '/../core/events.php';
require __DIR__ . '/../core/queries.php';
require __DIR__ . '/../core/step.php';
require __DIR__ . '/clock.php';
require __DIR__ . '/store.php';
require __DIR__ . '/format.php';
require __DIR__ . '/parse.php';
require __DIR__ . '/execute.php';
require __DIR__ . '/readers/sqlite.php';

use Tlon\Core\{CompleteInspection, FailInspection, State};

use function Tlon\Core\{absent_tables, change_report, columns_of, current_inspection,
    export_catalog, find_source_by_name, note_subject_absent, notes_of, referencing_tables,
    removal_summary, search_matches, source_notes, tables_of, column_count, last_seen_table, find_note};

function run(array $argv): int
{
    $path = state_path();
    $state = load_state($path);
    $command = $argv[1] ?? 'help';
    $args = array_slice($argv, 2);

    return match ($command) {
        'register' => apply($state, $path, parse_register($state, $args)),
        'remove' => apply($state, $path, parse_remove($state, $args)),
        'note' => apply($state, $path, parse_note($state, $args)),
        'inspect' => inspect($state, $path, $args),
        'reach' => reach($state, $args),
        'sources' => show_sources($state),
        'show' => show_source($state, $args),
        'changes' => show_changes($state, $args),
        'absent' => show_absent($state, $args),
        'search' => show_search($state, $args),
        'notes' => show_notes($state, $args),
        'export' => show_export($state),
        default => usage(),
    };
}

function apply(State $state, string $path, array $events): int
{
    $status = 0;
    foreach ($events as $event) {
        $result = \Tlon\Core\step($state, $event, now());
        $state = $result->state;
        save_state($state, $path);
        foreach ($result->actions as $action) {
            $status = max($status, execute($action));
        }
    }

    return $status;
}

function apply_quietly(State $state, string $path, array $events): State
{
    foreach ($events as $event) {
        $result = \Tlon\Core\step($state, $event, now());
        $state = $result->state;
        foreach ($result->actions as $action) {
            if ($action instanceof \Tlon\Core\Rejected) {
                execute($action);
            }
        }
    }
    save_state($state, $path);

    return $state;
}

function inspect(State $state, string $path, array $args): int
{
    $name = $args[0] ?? '';
    $connection = source_connection($state, $name);
    $started = parse_start_inspection($state, $args);
    $inspectionId = $started[0]->id;
    $begun = \Tlon\Core\step($state, $started[0], now());
    foreach ($begun->actions as $action) {
        if ($action instanceof \Tlon\Core\Rejected) {
            execute($action);

            return 1;
        }
    }
    $state = $begun->state;
    save_state($state, $path);
    try {
        $events = read_sqlite_schema($connection, $inspectionId);
    } catch (\Throwable $e) {
        $state = apply_quietly($state, $path, [new FailInspection($inspectionId, 'connect', $e->getMessage())]);
        emit_error('  inspection failed at connect: ' . $e->getMessage());

        return 1;
    }
    $state = apply_quietly($state, $path, $events);
    $state = apply_quietly($state, $path, [new CompleteInspection($inspectionId)]);
    emit('inspected ' . $name);
    emit_rows(
        array_map(fn ($t) => [$t->name, $t->kind, column_count($state, $inspectionId, $t->name)],
            tables_of($state, current_inspection($state, source_id($state, $name)))),
        ['name', 'kind', 'columns'],
    );

    return 0;
}

function reach(State $state, array $args): int
{
    $connection = source_connection($state, $args[0] ?? '');
    $failure = sqlite_reachable($connection);
    emit($failure === null ? 'reachable' : 'not reachable: ' . $failure);

    return $failure === null ? 0 : 1;
}

function show_sources(State $state): int
{
    emit('sources');
    emit_rows(
        array_map(fn ($s) => [$s->name, $s->engine, current_inspection($state, $s->id)?->endedAt ?? 'never inspected'], $state->sources),
        ['name', 'engine', 'last inspected'],
    );

    return 0;
}

function show_source(State $state, array $args): int
{
    $source = find_source_by_name($state, $args[0] ?? '');
    if ($source === null) {
        emit_error('  no such source');

        return 1;
    }
    $current = current_inspection($state, $source->id);
    if (isset($args[1])) {
        return show_table($state, $source->id, $args[1]);
    }
    emit('tables in ' . $source->name);
    emit_rows(
        array_map(fn ($t) => [
            $t->name, $t->kind, column_count($state, $current->id, $t->name),
            last_seen_table($state, $source->id, $t->name)?->endedAt ?? '',
        ], tables_of($state, $current)),
        ['name', 'kind', 'columns', 'last seen'],
    );
    emit('absent');
    emit_rows(array_map(fn ($n) => [$n], absent_tables($state, $source->id)), ['name']);

    return 0;
}

function show_table(State $state, string $sourceId, string $table): int
{
    $current = current_inspection($state, $sourceId);
    emit('columns of ' . $table);
    emit_rows(
        array_map(fn ($c) => [$c->position, $c->name, $c->declaredType, $c->acceptsNothing ? 'null' : 'not null', $c->hasDefault ? $c->defaultExpression : ''],
            columns_of($state, $current, $table)),
        ['#', 'name', 'type', 'nullable', 'default'],
    );
    emit('referenced by');
    emit_rows(array_map(fn ($r) => [$r->fromTable, $r->name, $r->onDelete], referencing_tables($state, $sourceId, $table)), ['table', 'reference', 'on delete']);

    return 0;
}

function show_changes(State $state, array $args): int
{
    $source = find_source_by_name($state, $args[0] ?? '');
    if ($source === null) {
        emit_error('  no such source');

        return 1;
    }
    $report = change_report($state, $source->id);
    emit('added');
    emit_rows(array_map(fn ($n) => [$n], $report['added']), ['table']);
    emit('absent');
    emit_rows(array_map(fn ($n) => [$n], $report['absent']), ['table']);
    emit('changed');
    emit_rows(array_map(fn ($k, $v) => [$k, json_encode($v)], array_keys($report['changed']), $report['changed']), ['table', 'detail']);

    return 0;
}

function show_absent(State $state, array $args): int
{
    $source = find_source_by_name($state, $args[0] ?? '');
    emit('absent');
    emit_rows(array_map(fn ($n) => [$n], absent_tables($state, $source?->id ?? '')), ['table']);

    return 0;
}

function show_search(State $state, array $args): int
{
    emit('matches');
    emit_rows(array_map(fn ($m) => [$m['source'], $m['table'], $m['column']], search_matches($state, $args[0] ?? '')), ['source', 'table', 'column']);

    return 0;
}

function show_notes(State $state, array $args): int
{
    $source = find_source_by_name($state, $args[0] ?? '');
    if ($source === null) {
        emit_error('  no such source');

        return 1;
    }
    emit('notes on ' . $source->name);
    emit_rows(
        array_map(fn ($n) => [$n->tableName, $n->columnName, note_subject_absent($state, $n->id) ? 'absent' : 'present', $n->body],
            source_notes($state, $source->id)),
        ['table', 'column', 'subject', 'note'],
    );

    return 0;
}

function show_export(State $state): int
{
    emit(json_encode(export_catalog($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return 0;
}

function usage(): int
{
    foreach ([
        'tlon register <name> <engine> <connection>',
        'tlon reach <name>',
        'tlon inspect <name>',
        'tlon sources',
        'tlon show <name> [table]',
        'tlon changes <name>',
        'tlon absent <name>',
        'tlon search <term>',
        'tlon note <name> <table> <column|-> <text...>',
        'tlon notes <name>',
        'tlon export',
        'tlon remove <name>',
    ] as $line) {
        emit($line);
    }

    return 0;
}

exit(run($argv));
