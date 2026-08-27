<?php

declare(strict_types=1);

require __DIR__ . '/../src/core/state.php';
require __DIR__ . '/../src/core/events.php';
require __DIR__ . '/../src/core/queries.php';
require __DIR__ . '/../src/core/step.php';

use function Tlon\Core\{empty_state, step, current_inspection, change_report, absent_tables,
    table_is_present, column_is_present, last_seen_table, column_count, referencing_tables,
    search_matches, note_subject_absent, removal_summary, stopping_point, export_catalog,
    columns_of, find_source, tables_of};
use Tlon\Core\{State, Rejected, Recorded, RegisterSource, RemoveSource, StartInspection,
    RecordTable, RecordColumn, RecordPrimaryKeyColumn, RecordReference, RecordReferenceColumn,
    RecordIndex, RecordIndexColumn, RecordUnavailable, CompleteInspection, FailInspection, AddNote};

$passed = 0;
$failures = [];

function check(string $name, callable $body): void
{
    global $passed, $failures;
    try {
        $body();
        $passed++;
    } catch (Throwable $e) {
        $failures[] = $name . ' — ' . $e->getMessage();
    }
}

function assertTrue(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
    }
}

function accepted(State $state, object $event, string $now = '2026-01-01T00:00:00Z'): State
{
    $result = step($state, $event, $now);
    if ($result->actions[0] instanceof Rejected) {
        throw new RuntimeException('unexpectedly rejected: ' . $result->actions[0]->code);
    }

    return $result->state;
}

function rejectedWith(State $state, object $event, string $code, string $now = '2026-01-01T00:00:00Z'): void
{
    $result = step($state, $event, $now);
    $action = $result->actions[0];
    assertTrue($action instanceof Rejected, 'expected a rejection, got acceptance');
    assertSame($code, $action->code, 'wrong rejection code');
    assertTrue($result->state === $state, 'state changed on a rejected event');
}

function seeded(): State
{
    $state = accepted(empty_state(), new RegisterSource('s1', 'warehouse', 'postgresql', 'dsn'));
    $state = accepted($state, new StartInspection('i1', 's1'), '2026-01-02T00:00:00Z');
    $state = accepted($state, new RecordTable('i1', 'people', 'table', 'humans', 120, 4096, '2026-01-02T00:00:00Z'));
    $state = accepted($state, new RecordColumn('i1', 'people', 'id', 1, 'bigint', false));
    $state = accepted($state, new RecordColumn('i1', 'people', 'email', 2, 'text', true, 'unknown', true));
    $state = accepted($state, new RecordTable('i1', 'people_view', 'view'));
    $state = accepted($state, new RecordColumn('i1', 'people_view', 'id', 1, 'bigint', false));

    return accepted($state, new CompleteInspection('i1'), '2026-01-02T01:00:00Z');
}

function reinspect(State $state, string $id, array $tables, string $at): State
{
    $state = accepted($state, new StartInspection($id, 's1'), $at);
    foreach ($tables as $table => $columns) {
        $state = accepted($state, new RecordTable($id, $table, 'table'));
        $position = 1;
        foreach ($columns as $column => $type) {
            $state = accepted($state, new RecordColumn($id, $table, $column, $position++, $type, false));
        }
    }

    return accepted($state, new CompleteInspection($id), $at);
}

check('R1 a registered source is listed', function () {
    $state = accepted(empty_state(), new RegisterSource('s1', 'warehouse', 'sqlite', '/tmp/db.sqlite'));
    assertSame(1, count($state->sources), 'source not stored');
    assertSame('warehouse', $state->sources[0]->name, 'name not stored');
});

check('R1 registration needs connection details', function () {
    rejectedWith(empty_state(), new RegisterSource('s1', 'warehouse', 'sqlite', '  '), 'connection_required');
});

check('R3 the export never carries the connection secret', function () {
    $export = export_catalog(seeded());
    assertTrue(! str_contains(json_encode($export), 'dsn'), 'the secret leaked into the export');
});

check('R4 removing a source reports what went with it', function () {
    $state = seeded();
    $summary = removal_summary($state, 's1');
    assertTrue($summary['inspections'] === 1 && $summary['observations'] > 0, 'summary wrong');
    $result = step($state, new RemoveSource('s1'), '2026-01-03T00:00:00Z');
    assertSame([], $result->state->sources, 'source survived');
    assertSame([], $result->state->tables, 'observations survived');
});

check('R4 removing an unknown source is refused', function () {
    rejectedWith(seeded(), new RemoveSource('nope'), 'no_such_source');
});

check('R5 tables and views are recorded and told apart', function () {
    $state = seeded();
    $tables = tables_of($state, current_inspection($state, 's1'));
    assertSame(2, count($tables), 'wrong number observed');
    $kinds = array_map(fn ($t) => $t->kind, $tables);
    assertTrue(in_array('table', $kinds, true) && in_array('view', $kinds, true), 'kinds not distinguished');
});

check('R6 columns keep declared order, type, nullability and default', function () {
    $state = seeded();
    $columns = columns_of($state, current_inspection($state, 's1'), 'people');
    assertSame(['id', 'email'], array_map(fn ($c) => $c->name, $columns), 'order lost');
    assertSame('bigint', $columns[0]->declaredType, 'type lost');
    assertTrue($columns[1]->acceptsNothing, 'nullability lost');
    assertTrue($columns[1]->hasDefault && $columns[1]->defaultExpression === 'unknown', 'default lost');
});

check('R7 a composite primary key keeps its column order', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'memberships', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'memberships', 'person_id', 1, 'bigint', false));
    $state = accepted($state, new RecordColumn('i2', 'memberships', 'group_id', 2, 'bigint', false));
    $state = accepted($state, new RecordPrimaryKeyColumn('i2', 'memberships', 1, 'person_id'));
    $state = accepted($state, new RecordPrimaryKeyColumn('i2', 'memberships', 2, 'group_id'));
    assertSame(2, count($state->primaryKeyColumns), 'key columns lost');
    assertSame('group_id', $state->primaryKeyColumns[1]->columnName, 'key order lost');
});

check('R8 a reference records both sides and its behaviour', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false));
    $state = accepted($state, new RecordTable('i2', 'orders', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'orders', 'person_id', 1, 'bigint', false));
    $state = accepted($state, new RecordReference('r1', 'i2', 'orders_person', 'orders', 'people', 'cascade', 'restrict'));
    $state = accepted($state, new RecordReferenceColumn('r1', 1, 'person_id', 'id'));
    assertSame('cascade', $state->references[0]->onUpdate, 'on update lost');
    assertSame('restrict', $state->references[0]->onDelete, 'on delete lost');
    assertSame('id', $state->referenceColumns[0]->toColumn, 'target column lost');
});

check('R9 an index records its columns and uniqueness', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'email', 1, 'text', false));
    $state = accepted($state, new RecordIndex('x1', 'i2', 'people', 'people_email', true));
    $state = accepted($state, new RecordIndexColumn('x1', 1, 'email'));
    assertTrue($state->indexes[0]->requiresUniqueness, 'uniqueness lost');
    assertSame('email', $state->indexColumns[0]->columnName, 'index column lost');
});

check('R10 size and estimate carry when they were measured', function () {
    $table = tables_of(seeded(), current_inspection(seeded(), 's1'))[0];
    assertSame(120, $table->rowEstimate, 'estimate lost');
    assertSame('2026-01-02T00:00:00Z', $table->measuredAt, 'measurement time lost');
});

check('R11 a description from the database is kept', function () {
    $table = tables_of(seeded(), current_inspection(seeded(), 's1'))[0];
    assertSame('humans', $table->description, 'description lost');
});

check('R12 unavailable is recorded, not treated as empty', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordUnavailable('i2', 'people', '', 'row_estimate'));
    assertSame('row_estimate', $state->unavailable[0]->datum, 'unavailable not recorded');
});

check('R13 re-inspection does not duplicate a table that still exists', function () {
    $state = reinspect(seeded(), 'i2', ['people' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    assertSame(1, count(tables_of($state, current_inspection($state, 's1'))), 'current inspection wrong');
    assertTrue(table_is_present($state, 's1', 'people'), 'people should be present');
});

check('R14 a table missing from the newest inspection reads absent', function () {
    $state = reinspect(seeded(), 'i2', ['people' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    assertSame(['people_view'], absent_tables($state, 's1'), 'absent set wrong');
    assertTrue(! table_is_present($state, 's1', 'people_view'), 'view should be absent');
});

check('R15 a returning table reads present again', function () {
    $state = reinspect(seeded(), 'i2', ['people' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    $state = reinspect($state, 'i3', ['people' => ['id' => 'bigint'], 'people_view' => ['id' => 'bigint']], '2026-01-04T00:00:00Z');
    assertSame([], absent_tables($state, 's1'), 'nothing should be absent');
});

check('R16 the change report names added, changed and absent', function () {
    $state = reinspect(seeded(), 'i2', ['people' => ['id' => 'bigint', 'name' => 'text'], 'orders' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    $report = change_report($state, 's1');
    assertSame(['orders'], $report['added'], 'added wrong');
    assertSame(['people_view'], $report['absent'], 'absent wrong');
    assertTrue(isset($report['changed']['people']['columns']['name']['added']), 'added column not reported');
    assertTrue(isset($report['changed']['people']['columns']['email']['absent']), 'absent column not reported');
});

check('R16 an unchanged re-inspection reports nothing', function () {
    $state = reinspect(seeded(), 'i2', ['people' => ['id' => 'bigint', 'email' => 'text'], 'people_view' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    $report = change_report($state, 's1');
    assertSame([], $report['added'], 'added should be empty');
    assertSame([], $report['absent'], 'absent should be empty');
});

check('R17 a failed inspection never makes anything absent', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new FailInspection('i2', 'columns', 'connection lost'), '2026-01-03T00:10:00Z');
    assertSame('i1', current_inspection($state, 's1')->id, 'a failed inspection became current');
    assertSame([], absent_tables($state, 's1'), 'a failure marked things absent');
    assertSame('columns', stopping_point($state, 'i2')['stoppedAt'], 'stopping point lost');
    assertSame(1, count(tables_of($state, \Tlon\Core\find_inspection($state, 'i2'))), 'partial reads discarded');
});

check('R18 a note is kept', function () {
    $state = accepted(seeded(), new AddNote('n1', 's1', 'people', '', 'the real customer table'));
    assertSame('the real customer table', $state->notes[0]->body, 'note lost');
});

check('R19 a note survives re-inspection', function () {
    $state = accepted(seeded(), new AddNote('n1', 's1', 'people', 'email', 'nullable on purpose'));
    $state = reinspect($state, 'i2', ['people' => ['id' => 'bigint', 'email' => 'text']], '2026-01-03T00:00:00Z');
    assertSame(1, count($state->notes), 'note lost on re-inspection');
    assertTrue(! note_subject_absent($state, 'n1'), 'subject should still be present');
});

check('R20 a note whose subject went away is kept and flagged', function () {
    $state = accepted(seeded(), new AddNote('n1', 's1', 'people', 'email', 'nullable on purpose'));
    $state = reinspect($state, 'i2', ['people' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    assertSame(1, count($state->notes), 'note lost');
    assertTrue(note_subject_absent($state, 'n1'), 'absent subject not flagged');
});

check('R21 column count and last seen are derived', function () {
    $state = seeded();
    assertSame(2, column_count($state, 'i1', 'people'), 'column count wrong');
    assertSame('i1', last_seen_table($state, 's1', 'people')->id, 'last seen wrong');
});

check('R22 a table reports its columns in order', function () {
    $columns = columns_of(seeded(), current_inspection(seeded(), 's1'), 'people');
    assertSame([1, 2], array_map(fn ($c) => $c->position, $columns), 'positions wrong');
});

check('R23 what references a table is derived', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false));
    $state = accepted($state, new RecordTable('i2', 'orders', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'orders', 'person_id', 1, 'bigint', false));
    $state = accepted($state, new RecordReference('r1', 'i2', 'fk', 'orders', 'people'));
    $state = accepted($state, new CompleteInspection('i2'), '2026-01-03T01:00:00Z');
    $found = referencing_tables($state, 's1', 'people');
    assertSame('orders', $found[0]->fromTable, 'referencing table not found');
});

check('R24 search finds tables and columns across sources', function () {
    $matches = search_matches(seeded(), 'peop');
    assertTrue(count($matches) >= 2, 'search found too little');
    assertSame('warehouse', $matches[0]['source'], 'source not labelled');
});

check('R25 same-named tables in two sources stay separate', function () {
    $state = accepted(seeded(), new RegisterSource('s2', 'replica', 'mysql', 'dsn2'));
    $state = accepted($state, new StartInspection('j1', 's2'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('j1', 'people', 'table'));
    $state = accepted($state, new CompleteInspection('j1'), '2026-01-03T01:00:00Z');
    $sources = array_column(search_matches($state, 'people'), 'source');
    assertTrue(in_array('warehouse', $sources, true) && in_array('replica', $sources, true), 'sources not separated');
});

check('R26 the export carries schema, absent things and notes', function () {
    $state = accepted(seeded(), new AddNote('n1', 's1', 'people', '', 'note body'));
    $state = reinspect($state, 'i2', ['people' => ['id' => 'bigint']], '2026-01-03T00:00:00Z');
    $export = export_catalog($state);
    assertSame('warehouse', $export[0]['source'], 'source missing');
    assertSame(['people_view'], $export[0]['absent'], 'absent missing');
    assertSame('note body', $export[0]['notes'][0]['body'], 'notes missing');
});

check('R26 the export carries keys, references and indexes', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false));
    $state = accepted($state, new RecordTable('i2', 'orders', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'orders', 'person_id', 1, 'bigint', false));
    $state = accepted($state, new RecordPrimaryKeyColumn('i2', 'people', 1, 'id'));
    $state = accepted($state, new RecordReference('r1', 'i2', 'fk', 'orders', 'people', 'cascade', 'restrict'));
    $state = accepted($state, new RecordReferenceColumn('r1', 1, 'person_id', 'id'));
    $state = accepted($state, new RecordIndex('x1', 'i2', 'people', 'people_id', true));
    $state = accepted($state, new RecordIndexColumn('x1', 1, 'id'));
    $state = accepted($state, new RecordUnavailable('i2', 'people', '', 'row_estimate'));
    $state = accepted($state, new CompleteInspection('i2'), '2026-01-03T01:00:00Z');
    $tables = [];
    foreach (export_catalog($state)[0]['tables'] as $table) {
        $tables[$table['name']] = $table;
    }
    assertSame(['id'], $tables['people']['primaryKey'], 'primary key missing from export');
    assertSame('restrict', $tables['orders']['references'][0]['onDelete'], 'reference behaviour missing');
    assertSame('people', $tables['orders']['references'][0]['to'], 'reference target missing');
    assertTrue($tables['people']['indexes'][0]['unique'], 'index uniqueness missing');
    assertSame(['row_estimate'], $tables['people']['unavailable'], 'unavailable missing from export');
});

check('R30 all three engines register and nothing else does', function () {
    $state = empty_state();
    foreach (['sqlite', 'mysql', 'postgresql'] as $i => $engine) {
        $state = accepted($state, new RegisterSource("e$i", "src$i", $engine, 'dsn'));
    }
    assertSame(3, count($state->sources), 'not all engines accepted');
    rejectedWith($state, new RegisterSource('e9', 'other', 'oracle', 'dsn'), 'unknown_engine');
});

check('C1 no two sources share a name', function () {
    rejectedWith(seeded(), new RegisterSource('s9', 'warehouse', 'mysql', 'dsn'), 'name_taken');
});

check('C2 the engine must be one of the three', function () {
    rejectedWith(empty_state(), new RegisterSource('s1', 'a', 'mongodb', 'dsn'), 'unknown_engine');
});

check('C3 an inspection cannot end before it started', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-05T00:00:00Z');
    rejectedWith($state, new CompleteInspection('i2'), 'ends_before_start', '2026-01-04T00:00:00Z');
});

check('C4 a failed inspection names the step that stopped it', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    rejectedWith($state, new FailInspection('i2', '', 'boom'), 'step_required', '2026-01-03T01:00:00Z');
});

check('C5 a table is a table or a view', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    rejectedWith($state, new RecordTable('i2', 'thing', 'materialized'), 'unknown_kind');
});

check('C6 a column belongs to a table in the same inspection', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    rejectedWith($state, new RecordColumn('i2', 'ghost', 'id', 1, 'bigint', false), 'no_such_table');
});

check('C7 column positions have no gaps', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    rejectedWith($state, new RecordColumn('i2', 'people', 'id', 2, 'bigint', false), 'position_gap');
});

check('C8 no default means no default expression', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    rejectedWith($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false, 'zero', false), 'default_mismatch');
});

check('C9 a key column names an observed column', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    rejectedWith($state, new RecordPrimaryKeyColumn('i2', 'people', 1, 'ghost'), 'no_such_column');
});

check('C10 key positions have no gaps', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false));
    rejectedWith($state, new RecordPrimaryKeyColumn('i2', 'people', 2, 'id'), 'position_gap');
});

check('C11 a reference names observed tables on both sides', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'orders', 'table'));
    rejectedWith($state, new RecordReference('r1', 'i2', 'fk', 'orders', 'ghost'), 'no_such_table');
});

check('C12 reference column positions have no gaps', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false));
    $state = accepted($state, new RecordReference('r1', 'i2', 'fk', 'people', 'people'));
    rejectedWith($state, new RecordReferenceColumn('r1', 2, 'id', 'id'), 'position_gap');
});

check('C13 reference columns are observed on their tables', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordColumn('i2', 'people', 'id', 1, 'bigint', false));
    $state = accepted($state, new RecordReference('r1', 'i2', 'fk', 'people', 'people'));
    rejectedWith($state, new RecordReferenceColumn('r1', 1, 'ghost', 'id'), 'no_such_column');
});

check('C14 an index names an observed table', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    rejectedWith($state, new RecordIndex('x1', 'i2', 'ghost', 'idx', false), 'no_such_table');
});

check('C15 an index column is observed on its table', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    $state = accepted($state, new RecordTable('i2', 'people', 'table'));
    $state = accepted($state, new RecordIndex('x1', 'i2', 'people', 'idx', false));
    rejectedWith($state, new RecordIndexColumn('x1', 1, 'ghost'), 'no_such_column');
});

check('C16 an unavailable column subject also names its table', function () {
    $state = accepted(seeded(), new StartInspection('i2', 's1'), '2026-01-03T00:00:00Z');
    rejectedWith($state, new RecordUnavailable('i2', '', 'email', 'description'), 'bad_subject');
});

check('C17 a note names a table that has been observed', function () {
    rejectedWith(seeded(), new AddNote('n1', 's1', 'ghost', '', 'body'), 'never_observed');
    rejectedWith(seeded(), new AddNote('n2', 's1', '', '', 'body'), 'table_required');
});

check('C18 observations require a running inspection', function () {
    rejectedWith(seeded(), new RecordTable('i1', 'late', 'table'), 'not_running');
});

check('step never mutates the state it was given', function () {
    $before = seeded();
    $count = count($before->tables);
    step($before, new RemoveSource('s1'), '2026-01-03T00:00:00Z');
    assertSame($count, count($before->tables), 'the original state was mutated');
});

check('an unknown event is rejected rather than thrown', function () {
    rejectedWith(seeded(), new stdClass(), 'unknown_event');
});

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL  " . $failure . "\n";
}
echo sprintf("\n%d passed, %d failed\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
