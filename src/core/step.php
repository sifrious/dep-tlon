<?php

declare(strict_types=1);

namespace Tlon\Core;

function step(State $state, object $event, string $now): StepResult
{
    return match (true) {
        $event instanceof RegisterSource => register_source($state, $event, $now),
        $event instanceof RemoveSource => remove_source($state, $event),
        $event instanceof StartInspection => start_inspection($state, $event, $now),
        $event instanceof RecordTable => record_table($state, $event),
        $event instanceof RecordColumn => record_column($state, $event),
        $event instanceof RecordPrimaryKeyColumn => record_primary_key_column($state, $event),
        $event instanceof RecordReference => record_reference($state, $event),
        $event instanceof RecordReferenceColumn => record_reference_column($state, $event),
        $event instanceof RecordIndex => record_index($state, $event),
        $event instanceof RecordIndexColumn => record_index_column($state, $event),
        $event instanceof RecordUnavailable => record_unavailable($state, $event),
        $event instanceof CompleteInspection => complete_inspection($state, $event, $now),
        $event instanceof FailInspection => fail_inspection($state, $event, $now),
        $event instanceof AddNote => add_note($state, $event, $now),
        default => reject($state, 'unknown_event', 'The core does not handle this event.'),
    };
}

function reject(State $state, string $code, string $reason): StepResult
{
    return new StepResult($state, [new Rejected($code, $reason)]);
}

function accept(State $state, string $relation, object $row, string $what, array $detail = []): StepResult
{
    return new StepResult($state->withAdded($relation, $row), [new Recorded($what, $detail)]);
}

function running_inspection(State $state, string $inspectionId): ?Inspection
{
    $inspection = find_inspection($state, $inspectionId);

    return $inspection !== null && $inspection->outcome === Inspection::RUNNING ? $inspection : null;
}

function register_source(State $state, RegisterSource $event, string $now): StepResult
{
    if (find_source($state, $event->id) !== null) {
        return reject($state, 'source_exists', 'A source with that identifier is already registered.');
    }
    if (! in_array($event->engine, Source::ENGINES, true)) {
        return reject($state, 'unknown_engine', 'The engine must be sqlite, mysql, or postgresql.');
    }
    if (trim($event->name) === '') {
        return reject($state, 'name_required', 'A source needs a name.');
    }
    if (find_source_by_name($state, $event->name) !== null) {
        return reject($state, 'name_taken', 'Another source already uses that name.');
    }
    if (trim($event->connection) === '') {
        return reject($state, 'connection_required', 'A source needs connection details.');
    }

    return accept($state, 'sources', new Source($event->id, $event->name, $event->engine, $event->connection, $now), 'source registered');
}

function remove_source(State $state, RemoveSource $event): StepResult
{
    if (find_source($state, $event->sourceId) === null) {
        return reject($state, 'no_such_source', 'That source is not registered.');
    }
    $summary = removal_summary($state, $event->sourceId);
    $keptInspections = array_values(array_filter($state->inspections, fn (Inspection $i) => $i->sourceId !== $event->sourceId));
    $keptIds = array_map(fn (Inspection $i) => $i->id, $keptInspections);
    $byInspection = fn (object $row) => in_array($row->inspectionId, $keptIds, true);
    $referenceIds = array_map(fn (ObservedReference $r) => $r->id, array_values(array_filter($state->references, $byInspection)));
    $indexIds = array_map(fn (ObservedIndex $i) => $i->id, array_values(array_filter($state->indexes, $byInspection)));

    return new StepResult($state->with([
        'sources' => array_values(array_filter($state->sources, fn (Source $s) => $s->id !== $event->sourceId)),
        'inspections' => $keptInspections,
        'tables' => array_values(array_filter($state->tables, $byInspection)),
        'columns' => array_values(array_filter($state->columns, $byInspection)),
        'primaryKeyColumns' => array_values(array_filter($state->primaryKeyColumns, $byInspection)),
        'references' => array_values(array_filter($state->references, $byInspection)),
        'referenceColumns' => array_values(array_filter($state->referenceColumns, fn (ObservedReferenceColumn $c) => in_array($c->referenceId, $referenceIds, true))),
        'indexes' => array_values(array_filter($state->indexes, $byInspection)),
        'indexColumns' => array_values(array_filter($state->indexColumns, fn (ObservedIndexColumn $c) => in_array($c->indexId, $indexIds, true))),
        'unavailable' => array_values(array_filter($state->unavailable, $byInspection)),
        'notes' => array_values(array_filter($state->notes, fn (Note $n) => $n->sourceId !== $event->sourceId)),
    ]), [new Recorded('source removed', $summary)]);
}

function start_inspection(State $state, StartInspection $event, string $now): StepResult
{
    if (find_source($state, $event->sourceId) === null) {
        return reject($state, 'no_such_source', 'That source is not registered.');
    }
    if (find_inspection($state, $event->id) !== null) {
        return reject($state, 'inspection_exists', 'An inspection with that identifier already exists.');
    }

    return accept($state, 'inspections', new Inspection($event->id, $event->sourceId, $now), 'inspection started');
}

function record_table(State $state, RecordTable $event): StepResult
{
    if (running_inspection($state, $event->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if (! in_array($event->kind, ObservedTable::KINDS, true)) {
        return reject($state, 'unknown_kind', 'A table is either a table or a view.');
    }
    if (find_observed_table($state, $event->inspectionId, $event->name) !== null) {
        return reject($state, 'table_seen', 'That table was already observed in this inspection.');
    }

    return accept($state, 'tables', new ObservedTable(
        $event->inspectionId, $event->name, $event->kind, $event->description,
        $event->rowEstimate, $event->sizeBytes, $event->measuredAt,
    ), 'table observed');
}

function record_column(State $state, RecordColumn $event): StepResult
{
    if (running_inspection($state, $event->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if (find_observed_table($state, $event->inspectionId, $event->tableName) === null) {
        return reject($state, 'no_such_table', 'A column belongs to a table observed in the same inspection.');
    }
    if (find_observed_column($state, $event->inspectionId, $event->tableName, $event->name) !== null) {
        return reject($state, 'column_seen', 'That column was already observed in this inspection.');
    }
    if ($event->position !== column_count($state, $event->inspectionId, $event->tableName) + 1) {
        return reject($state, 'position_gap', 'Column positions start at one and have no gaps.');
    }
    if (! $event->hasDefault && $event->defaultExpression !== '') {
        return reject($state, 'default_mismatch', 'A column with no default carries no default expression.');
    }

    return accept($state, 'columns', new ObservedColumn(
        $event->inspectionId, $event->tableName, $event->name, $event->position,
        $event->declaredType, $event->acceptsNothing, $event->defaultExpression,
        $event->hasDefault, $event->description,
    ), 'column observed');
}

function primary_key_size(State $state, string $inspectionId, string $tableName): int
{
    $count = 0;
    foreach ($state->primaryKeyColumns as $row) {
        if ($row->inspectionId === $inspectionId && $row->tableName === $tableName) {
            $count++;
        }
    }

    return $count;
}

function record_primary_key_column(State $state, RecordPrimaryKeyColumn $event): StepResult
{
    if (running_inspection($state, $event->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if (find_observed_column($state, $event->inspectionId, $event->tableName, $event->columnName) === null) {
        return reject($state, 'no_such_column', 'A key column names a column observed on the same table.');
    }
    if ($event->position !== primary_key_size($state, $event->inspectionId, $event->tableName) + 1) {
        return reject($state, 'position_gap', 'Key positions start at one and have no gaps.');
    }

    return accept($state, 'primaryKeyColumns', new ObservedPrimaryKeyColumn(
        $event->inspectionId, $event->tableName, $event->position, $event->columnName,
    ), 'primary key column observed');
}

function record_reference(State $state, RecordReference $event): StepResult
{
    if (running_inspection($state, $event->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if (find_reference($state, $event->id) !== null) {
        return reject($state, 'reference_exists', 'A reference with that identifier already exists.');
    }
    if (find_observed_table($state, $event->inspectionId, $event->fromTable) === null) {
        return reject($state, 'no_such_table', 'A reference starts at a table observed in the same inspection.');
    }
    if (find_observed_table($state, $event->inspectionId, $event->toTable) === null) {
        return reject($state, 'no_such_table', 'A reference points at a table observed in the same inspection.');
    }

    return accept($state, 'references', new ObservedReference(
        $event->id, $event->inspectionId, $event->name, $event->fromTable,
        $event->toTable, $event->onUpdate, $event->onDelete,
    ), 'reference observed');
}

function reference_column_count(State $state, string $referenceId): int
{
    $count = 0;
    foreach ($state->referenceColumns as $row) {
        if ($row->referenceId === $referenceId) {
            $count++;
        }
    }

    return $count;
}

function record_reference_column(State $state, RecordReferenceColumn $event): StepResult
{
    $reference = find_reference($state, $event->referenceId);
    if ($reference === null) {
        return reject($state, 'no_such_reference', 'That reference does not exist.');
    }
    if (running_inspection($state, $reference->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if ($event->position !== reference_column_count($state, $event->referenceId) + 1) {
        return reject($state, 'position_gap', 'Reference column positions start at one and have no gaps.');
    }
    if (find_observed_column($state, $reference->inspectionId, $reference->fromTable, $event->fromColumn) === null) {
        return reject($state, 'no_such_column', 'The referencing column is not observed on its table.');
    }
    if (find_observed_column($state, $reference->inspectionId, $reference->toTable, $event->toColumn) === null) {
        return reject($state, 'no_such_column', 'The referenced column is not observed on its table.');
    }

    return accept($state, 'referenceColumns', new ObservedReferenceColumn(
        $event->referenceId, $event->position, $event->fromColumn, $event->toColumn,
    ), 'reference column observed');
}

function record_index(State $state, RecordIndex $event): StepResult
{
    if (running_inspection($state, $event->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if (find_index($state, $event->id) !== null) {
        return reject($state, 'index_exists', 'An index with that identifier already exists.');
    }
    if (find_observed_table($state, $event->inspectionId, $event->tableName) === null) {
        return reject($state, 'no_such_table', 'An index belongs to a table observed in the same inspection.');
    }

    return accept($state, 'indexes', new ObservedIndex(
        $event->id, $event->inspectionId, $event->tableName, $event->name, $event->requiresUniqueness,
    ), 'index observed');
}

function index_column_count(State $state, string $indexId): int
{
    $count = 0;
    foreach ($state->indexColumns as $row) {
        if ($row->indexId === $indexId) {
            $count++;
        }
    }

    return $count;
}

function record_index_column(State $state, RecordIndexColumn $event): StepResult
{
    $index = find_index($state, $event->indexId);
    if ($index === null) {
        return reject($state, 'no_such_index', 'That index does not exist.');
    }
    if (running_inspection($state, $index->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if ($event->position !== index_column_count($state, $event->indexId) + 1) {
        return reject($state, 'position_gap', 'Index column positions start at one and have no gaps.');
    }
    if (find_observed_column($state, $index->inspectionId, $index->tableName, $event->columnName) === null) {
        return reject($state, 'no_such_column', 'The indexed column is not observed on its table.');
    }

    return accept($state, 'indexColumns', new ObservedIndexColumn(
        $event->indexId, $event->position, $event->columnName,
    ), 'index column observed');
}

function record_unavailable(State $state, RecordUnavailable $event): StepResult
{
    if (running_inspection($state, $event->inspectionId) === null) {
        return reject($state, 'not_running', 'Observations belong to a running inspection.');
    }
    if ($event->datum === '') {
        return reject($state, 'datum_required', 'An unavailable record names which fact was unavailable.');
    }
    if ($event->tableName === '' && $event->columnName !== '') {
        return reject($state, 'bad_subject', 'A column subject also names its table.');
    }
    if ($event->tableName !== '' && find_observed_table($state, $event->inspectionId, $event->tableName) === null) {
        return reject($state, 'no_such_table', 'An unavailable record names a table observed in the same inspection.');
    }
    if ($event->columnName !== '' && find_observed_column($state, $event->inspectionId, $event->tableName, $event->columnName) === null) {
        return reject($state, 'no_such_column', 'An unavailable record names a column observed in the same inspection.');
    }

    return accept($state, 'unavailable', new UnavailableDatum(
        $event->inspectionId, $event->tableName, $event->columnName, $event->datum,
    ), 'unavailable recorded');
}

function replace_inspection(State $state, Inspection $replacement): State
{
    return $state->with([
        'inspections' => array_map(
            fn (Inspection $i) => $i->id === $replacement->id ? $replacement : $i,
            $state->inspections,
        ),
    ]);
}

function complete_inspection(State $state, CompleteInspection $event, string $now): StepResult
{
    $inspection = running_inspection($state, $event->inspectionId);
    if ($inspection === null) {
        return reject($state, 'not_running', 'Only a running inspection can be completed.');
    }
    if ($now < $inspection->startedAt) {
        return reject($state, 'ends_before_start', 'An inspection cannot end before it started.');
    }

    return new StepResult(
        replace_inspection($state, new Inspection(
            $inspection->id, $inspection->sourceId, $inspection->startedAt, $now, Inspection::COMPLETED,
        )),
        [new Recorded('inspection completed')],
    );
}

function fail_inspection(State $state, FailInspection $event, string $now): StepResult
{
    $inspection = running_inspection($state, $event->inspectionId);
    if ($inspection === null) {
        return reject($state, 'not_running', 'Only a running inspection can fail.');
    }
    if (trim($event->stoppedAt) === '') {
        return reject($state, 'step_required', 'A failed inspection names the step that stopped it.');
    }
    if ($now < $inspection->startedAt) {
        return reject($state, 'ends_before_start', 'An inspection cannot end before it started.');
    }

    return new StepResult(
        replace_inspection($state, new Inspection(
            $inspection->id, $inspection->sourceId, $inspection->startedAt, $now,
            Inspection::FAILED, $event->stoppedAt, $event->failure,
        )),
        [new Recorded('inspection failed', ['stoppedAt' => $event->stoppedAt])],
    );
}

function table_ever_observed(State $state, string $sourceId, string $tableName): bool
{
    return in_array($tableName, ever_observed_table_names($state, $sourceId), true);
}

function column_ever_observed(State $state, string $sourceId, string $tableName, string $columnName): bool
{
    foreach (completed_inspections($state, $sourceId) as $inspection) {
        if (find_observed_column($state, $inspection->id, $tableName, $columnName) !== null) {
            return true;
        }
    }

    return false;
}

function add_note(State $state, AddNote $event, string $now): StepResult
{
    if (find_source($state, $event->sourceId) === null) {
        return reject($state, 'no_such_source', 'That source is not registered.');
    }
    if (find_note($state, $event->id) !== null) {
        return reject($state, 'note_exists', 'A note with that identifier already exists.');
    }
    if (trim($event->tableName) === '') {
        return reject($state, 'table_required', 'A note names a table.');
    }
    if (trim($event->body) === '') {
        return reject($state, 'body_required', 'A note needs something written in it.');
    }
    if (! table_ever_observed($state, $event->sourceId, $event->tableName)) {
        return reject($state, 'never_observed', 'That table has never been observed in this source.');
    }
    if ($event->columnName !== '' && ! column_ever_observed($state, $event->sourceId, $event->tableName, $event->columnName)) {
        return reject($state, 'never_observed', 'That column has never been observed in this source.');
    }

    return accept($state, 'notes', new Note(
        $event->id, $event->sourceId, $event->tableName, $event->columnName, $event->body, $now,
    ), 'note added');
}
