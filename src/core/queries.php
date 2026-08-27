<?php

declare(strict_types=1);

namespace Tlon\Core;

function find_source(State $state, string $sourceId): ?Source
{
    foreach ($state->sources as $source) {
        if ($source->id === $sourceId) {
            return $source;
        }
    }

    return null;
}

function find_source_by_name(State $state, string $name): ?Source
{
    foreach ($state->sources as $source) {
        if ($source->name === $name) {
            return $source;
        }
    }

    return null;
}

function find_inspection(State $state, string $inspectionId): ?Inspection
{
    foreach ($state->inspections as $inspection) {
        if ($inspection->id === $inspectionId) {
            return $inspection;
        }
    }

    return null;
}

function find_observed_table(State $state, string $inspectionId, string $name): ?ObservedTable
{
    foreach ($state->tables as $table) {
        if ($table->inspectionId === $inspectionId && $table->name === $name) {
            return $table;
        }
    }

    return null;
}

function find_observed_column(State $state, string $inspectionId, string $tableName, string $name): ?ObservedColumn
{
    foreach ($state->columns as $column) {
        if ($column->inspectionId === $inspectionId && $column->tableName === $tableName && $column->name === $name) {
            return $column;
        }
    }

    return null;
}

function find_reference(State $state, string $referenceId): ?ObservedReference
{
    foreach ($state->references as $reference) {
        if ($reference->id === $referenceId) {
            return $reference;
        }
    }

    return null;
}

function find_index(State $state, string $indexId): ?ObservedIndex
{
    foreach ($state->indexes as $index) {
        if ($index->id === $indexId) {
            return $index;
        }
    }

    return null;
}

function find_note(State $state, string $noteId): ?Note
{
    foreach ($state->notes as $note) {
        if ($note->id === $noteId) {
            return $note;
        }
    }

    return null;
}

function completed_inspections(State $state, string $sourceId): array
{
    $found = [];
    foreach ($state->inspections as $inspection) {
        if ($inspection->sourceId === $sourceId && $inspection->outcome === Inspection::COMPLETED) {
            $found[] = $inspection;
        }
    }
    usort($found, fn (Inspection $a, Inspection $b) => $a->endedAt <=> $b->endedAt ?: $a->id <=> $b->id);

    return $found;
}

function current_inspection(State $state, string $sourceId): ?Inspection
{
    $completed = completed_inspections($state, $sourceId);

    return $completed === [] ? null : $completed[count($completed) - 1];
}

function previous_inspection(State $state, string $sourceId): ?Inspection
{
    $completed = completed_inspections($state, $sourceId);

    return count($completed) < 2 ? null : $completed[count($completed) - 2];
}

function tables_of(State $state, ?Inspection $inspection): array
{
    if ($inspection === null) {
        return [];
    }
    $found = [];
    foreach ($state->tables as $table) {
        if ($table->inspectionId === $inspection->id) {
            $found[] = $table;
        }
    }

    return $found;
}

function columns_of(State $state, ?Inspection $inspection, string $tableName): array
{
    if ($inspection === null) {
        return [];
    }
    $found = [];
    foreach ($state->columns as $column) {
        if ($column->inspectionId === $inspection->id && $column->tableName === $tableName) {
            $found[] = $column;
        }
    }
    usort($found, fn (ObservedColumn $a, ObservedColumn $b) => $a->position <=> $b->position);

    return $found;
}

function current_schema_tables(State $state, string $sourceId): array
{
    return tables_of($state, current_inspection($state, $sourceId));
}

function table_is_present(State $state, string $sourceId, string $tableName): bool
{
    return find_observed_table($state, current_inspection($state, $sourceId)?->id ?? '', $tableName) !== null;
}

function column_is_present(State $state, string $sourceId, string $tableName, string $columnName): bool
{
    $current = current_inspection($state, $sourceId);

    return $current !== null
        && find_observed_column($state, $current->id, $tableName, $columnName) !== null;
}

function ever_observed_table_names(State $state, string $sourceId): array
{
    $names = [];
    foreach (completed_inspections($state, $sourceId) as $inspection) {
        foreach (tables_of($state, $inspection) as $table) {
            $names[$table->name] = true;
        }
    }

    return array_keys($names);
}

function absent_tables(State $state, string $sourceId): array
{
    $absent = [];
    foreach (ever_observed_table_names($state, $sourceId) as $name) {
        if (! table_is_present($state, $sourceId, $name)) {
            $absent[] = $name;
        }
    }

    return $absent;
}

function last_seen_table(State $state, string $sourceId, string $tableName): ?Inspection
{
    $seen = null;
    foreach (completed_inspections($state, $sourceId) as $inspection) {
        if (find_observed_table($state, $inspection->id, $tableName) !== null) {
            $seen = $inspection;
        }
    }

    return $seen;
}

function column_count(State $state, string $inspectionId, string $tableName): int
{
    $count = 0;
    foreach ($state->columns as $column) {
        if ($column->inspectionId === $inspectionId && $column->tableName === $tableName) {
            $count++;
        }
    }

    return $count;
}

function table_facts(ObservedTable $table): array
{
    return [
        'kind' => $table->kind,
        'description' => $table->description,
        'rowEstimate' => $table->rowEstimate,
        'sizeBytes' => $table->sizeBytes,
    ];
}

function column_facts(ObservedColumn $column): array
{
    return [
        'position' => $column->position,
        'declaredType' => $column->declaredType,
        'acceptsNothing' => $column->acceptsNothing,
        'defaultExpression' => $column->defaultExpression,
        'hasDefault' => $column->hasDefault,
        'description' => $column->description,
    ];
}

function changed_facts(array $before, array $after): array
{
    $changed = [];
    foreach ($after as $key => $value) {
        if (($before[$key] ?? null) !== $value) {
            $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
        }
    }

    return $changed;
}

function table_names_of(State $state, ?Inspection $inspection): array
{
    return array_map(fn (ObservedTable $t) => $t->name, tables_of($state, $inspection));
}

function change_report(State $state, string $sourceId): array
{
    $current = current_inspection($state, $sourceId);
    $previous = previous_inspection($state, $sourceId);
    if ($current === null || $previous === null) {
        return ['added' => [], 'changed' => [], 'absent' => []];
    }
    $now = table_names_of($state, $current);
    $then = table_names_of($state, $previous);

    return [
        'added' => array_values(array_diff($now, $then)),
        'changed' => changed_tables($state, $current, $previous, array_intersect($now, $then)),
        'absent' => array_values(array_diff($then, $now)),
    ];
}

function changed_tables(State $state, Inspection $current, Inspection $previous, array $shared): array
{
    $changed = [];
    foreach ($shared as $name) {
        $facts = changed_facts(
            table_facts(find_observed_table($state, $previous->id, $name)),
            table_facts(find_observed_table($state, $current->id, $name)),
        );
        $columns = changed_columns($state, $current, $previous, $name);
        if ($facts !== [] || $columns !== []) {
            $changed[$name] = ['table' => $facts, 'columns' => $columns];
        }
    }

    return $changed;
}

function changed_columns(State $state, Inspection $current, Inspection $previous, string $tableName): array
{
    $changed = [];
    $before = columns_of($state, $previous, $tableName);
    $after = columns_of($state, $current, $tableName);
    $beforeNames = array_map(fn (ObservedColumn $c) => $c->name, $before);
    $afterNames = array_map(fn (ObservedColumn $c) => $c->name, $after);
    foreach (array_diff($afterNames, $beforeNames) as $name) {
        $changed[$name] = ['added' => true];
    }
    foreach (array_diff($beforeNames, $afterNames) as $name) {
        $changed[$name] = ['absent' => true];
    }
    foreach (array_intersect($afterNames, $beforeNames) as $name) {
        $facts = changed_facts(
            column_facts(find_observed_column($state, $previous->id, $tableName, $name)),
            column_facts(find_observed_column($state, $current->id, $tableName, $name)),
        );
        if ($facts !== []) {
            $changed[$name] = $facts;
        }
    }

    return $changed;
}

function referencing_tables(State $state, string $sourceId, string $tableName): array
{
    $current = current_inspection($state, $sourceId);
    if ($current === null) {
        return [];
    }
    $found = [];
    foreach ($state->references as $reference) {
        if ($reference->inspectionId === $current->id && $reference->toTable === $tableName) {
            $found[] = $reference;
        }
    }

    return $found;
}

function search_matches(State $state, string $term): array
{
    if ($term === '') {
        return [];
    }
    $matches = [];
    foreach ($state->sources as $source) {
        $current = current_inspection($state, $source->id);
        foreach (tables_of($state, $current) as $table) {
            if (stripos($table->name, $term) !== false) {
                $matches[] = ['source' => $source->name, 'table' => $table->name, 'column' => ''];
            }
            foreach (columns_of($state, $current, $table->name) as $column) {
                if (stripos($column->name, $term) !== false) {
                    $matches[] = ['source' => $source->name, 'table' => $table->name, 'column' => $column->name];
                }
            }
        }
    }

    return $matches;
}

function note_subject_absent(State $state, string $noteId): bool
{
    $note = find_note($state, $noteId);
    if ($note === null) {
        return false;
    }
    if ($note->columnName === '') {
        return ! table_is_present($state, $note->sourceId, $note->tableName);
    }

    return ! column_is_present($state, $note->sourceId, $note->tableName, $note->columnName);
}

function removal_summary(State $state, string $sourceId): array
{
    $inspectionIds = [];
    foreach ($state->inspections as $inspection) {
        if ($inspection->sourceId === $sourceId) {
            $inspectionIds[$inspection->id] = true;
        }
    }
    $observations = 0;
    foreach ([$state->tables, $state->columns, $state->primaryKeyColumns, $state->references, $state->indexes, $state->unavailable] as $relation) {
        foreach ($relation as $row) {
            if (isset($inspectionIds[$row->inspectionId])) {
                $observations++;
            }
        }
    }
    $notes = 0;
    foreach ($state->notes as $note) {
        if ($note->sourceId === $sourceId) {
            $notes++;
        }
    }

    return ['inspections' => count($inspectionIds), 'observations' => $observations, 'notes' => $notes];
}

function stopping_point(State $state, string $inspectionId): ?array
{
    $inspection = find_inspection($state, $inspectionId);
    if ($inspection === null || $inspection->outcome !== Inspection::FAILED) {
        return null;
    }

    return ['stoppedAt' => $inspection->stoppedAt, 'failure' => $inspection->failure];
}

function primary_key_of(State $state, ?Inspection $inspection, string $tableName): array
{
    if ($inspection === null) {
        return [];
    }
    $found = [];
    foreach ($state->primaryKeyColumns as $row) {
        if ($row->inspectionId === $inspection->id && $row->tableName === $tableName) {
            $found[] = $row;
        }
    }
    usort($found, fn (ObservedPrimaryKeyColumn $a, ObservedPrimaryKeyColumn $b) => $a->position <=> $b->position);

    return array_map(fn (ObservedPrimaryKeyColumn $c) => $c->columnName, $found);
}

function references_of(State $state, ?Inspection $inspection, string $tableName): array
{
    if ($inspection === null) {
        return [];
    }
    $found = [];
    foreach ($state->references as $reference) {
        if ($reference->inspectionId !== $inspection->id || $reference->fromTable !== $tableName) {
            continue;
        }
        $pairs = [];
        foreach ($state->referenceColumns as $column) {
            if ($column->referenceId === $reference->id) {
                $pairs[$column->position] = ['from' => $column->fromColumn, 'to' => $column->toColumn];
            }
        }
        ksort($pairs);
        $found[] = [
            'name' => $reference->name,
            'to' => $reference->toTable,
            'columns' => array_values($pairs),
            'onUpdate' => $reference->onUpdate,
            'onDelete' => $reference->onDelete,
        ];
    }

    return $found;
}

function indexes_of(State $state, ?Inspection $inspection, string $tableName): array
{
    if ($inspection === null) {
        return [];
    }
    $found = [];
    foreach ($state->indexes as $index) {
        if ($index->inspectionId !== $inspection->id || $index->tableName !== $tableName) {
            continue;
        }
        $columns = [];
        foreach ($state->indexColumns as $column) {
            if ($column->indexId === $index->id) {
                $columns[$column->position] = $column->columnName;
            }
        }
        ksort($columns);
        $found[] = ['name' => $index->name, 'unique' => $index->requiresUniqueness, 'columns' => array_values($columns)];
    }

    return $found;
}

function unavailable_of(State $state, ?Inspection $inspection, string $tableName): array
{
    if ($inspection === null) {
        return [];
    }
    $found = [];
    foreach ($state->unavailable as $row) {
        if ($row->inspectionId === $inspection->id && $row->tableName === $tableName) {
            $found[] = $row->columnName === '' ? $row->datum : $row->columnName . '.' . $row->datum;
        }
    }

    return $found;
}

function export_catalog(State $state): array
{
    $export = [];
    foreach ($state->sources as $source) {
        $current = current_inspection($state, $source->id);
        $tables = [];
        foreach (tables_of($state, $current) as $table) {
            $tables[] = [
                'name' => $table->name,
                'kind' => $table->kind,
                'description' => $table->description,
                'rowEstimate' => $table->rowEstimate,
                'sizeBytes' => $table->sizeBytes,
                'columns' => array_map(fn (ObservedColumn $c) => [
                    'name' => $c->name,
                    'position' => $c->position,
                    'declaredType' => $c->declaredType,
                    'acceptsNothing' => $c->acceptsNothing,
                    'hasDefault' => $c->hasDefault,
                    'defaultExpression' => $c->defaultExpression,
                    'description' => $c->description,
                ], columns_of($state, $current, $table->name)),
                'primaryKey' => primary_key_of($state, $current, $table->name),
                'references' => references_of($state, $current, $table->name),
                'indexes' => indexes_of($state, $current, $table->name),
                'unavailable' => unavailable_of($state, $current, $table->name),
            ];
        }
        $export[] = [
            'source' => $source->name,
            'engine' => $source->engine,
            'tables' => $tables,
            'absent' => absent_tables($state, $source->id),
            'notes' => notes_of($state, $source->id),
        ];
    }

    return $export;
}

function source_notes(State $state, string $sourceId): array
{
    $found = [];
    foreach ($state->notes as $note) {
        if ($note->sourceId === $sourceId) {
            $found[] = $note;
        }
    }

    return $found;
}

function notes_of(State $state, string $sourceId): array
{
    $found = [];
    foreach ($state->notes as $note) {
        if ($note->sourceId === $sourceId) {
            $found[] = ['table' => $note->tableName, 'column' => $note->columnName, 'body' => $note->body];
        }
    }

    return $found;
}
