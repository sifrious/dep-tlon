<?php

declare(strict_types=1);

namespace Tlon\Shell;

use PDO;
use Tlon\Core\{RecordColumn, RecordIndex, RecordIndexColumn, RecordPrimaryKeyColumn,
    RecordReference, RecordReferenceColumn, RecordTable, RecordUnavailable};

function sqlite_connection(string $connection): PDO
{
    $pdo = new PDO('sqlite:' . $connection, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->query('SELECT 1');

    return $pdo;
}

function sqlite_reachable(string $connection): ?string
{
    try {
        sqlite_connection($connection);

        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

function read_sqlite_schema(string $connection, string $inspectionId): array
{
    $pdo = sqlite_connection($connection);
    $events = [];
    $objects = $pdo->query(
        "SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll();

    foreach ($objects as $object) {
        $events[] = new RecordTable($inspectionId, $object['name'], $object['type']);
        foreach (['row_estimate', 'size_bytes', 'description'] as $datum) {
            $events[] = new RecordUnavailable($inspectionId, $object['name'], '', $datum);
        }
    }
    foreach ($objects as $object) {
        $events = [...$events, ...sqlite_column_events($pdo, $inspectionId, $object['name'])];
    }
    foreach ($objects as $object) {
        $events = [...$events, ...sqlite_reference_events($pdo, $inspectionId, $object['name'])];
        $events = [...$events, ...sqlite_index_events($pdo, $inspectionId, $object['name'])];
    }

    return $events;
}

function sqlite_column_events(PDO $pdo, string $inspectionId, string $table): array
{
    $rows = $pdo->query('PRAGMA table_info(' . quote_identifier($table) . ')')->fetchAll();
    $events = [];
    $keyed = [];
    foreach ($rows as $row) {
        $events[] = new RecordColumn(
            $inspectionId,
            $table,
            $row['name'],
            (int) $row['cid'] + 1,
            (string) $row['type'],
            (int) $row['notnull'] === 0,
            $row['dflt_value'] === null ? '' : (string) $row['dflt_value'],
            $row['dflt_value'] !== null,
        );
        $events[] = new RecordUnavailable($inspectionId, $table, $row['name'], 'description');
        if ((int) $row['pk'] > 0) {
            $keyed[(int) $row['pk']] = $row['name'];
        }
    }
    ksort($keyed);
    $position = 1;
    foreach ($keyed as $name) {
        $events[] = new RecordPrimaryKeyColumn($inspectionId, $table, $position++, $name);
    }

    return $events;
}

function sqlite_reference_events(PDO $pdo, string $inspectionId, string $table): array
{
    $rows = $pdo->query('PRAGMA foreign_key_list(' . quote_identifier($table) . ')')->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int) $row['id']][] = $row;
    }
    $events = [];
    foreach ($grouped as $id => $group) {
        $referenceId = $inspectionId . '-fk-' . $table . '-' . $id;
        $events[] = new RecordReference(
            $referenceId,
            $inspectionId,
            $table . '_fk_' . $id,
            $table,
            $group[0]['table'],
            (string) $group[0]['on_update'],
            (string) $group[0]['on_delete'],
        );
        $position = 1;
        foreach ($group as $pair) {
            $events[] = new RecordReferenceColumn(
                $referenceId,
                $position++,
                (string) $pair['from'],
                (string) $pair['to'],
            );
        }
    }

    return $events;
}

function sqlite_index_events(PDO $pdo, string $inspectionId, string $table): array
{
    $rows = $pdo->query('PRAGMA index_list(' . quote_identifier($table) . ')')->fetchAll();
    $events = [];
    foreach ($rows as $row) {
        $indexId = $inspectionId . '-ix-' . $table . '-' . $row['name'];
        $events[] = new RecordIndex(
            $indexId,
            $inspectionId,
            $table,
            (string) $row['name'],
            (int) $row['unique'] === 1,
        );
        $position = 1;
        foreach ($pdo->query('PRAGMA index_info(' . quote_identifier((string) $row['name']) . ')')->fetchAll() as $member) {
            if ($member['name'] === null) {
                $events[] = new RecordUnavailable($inspectionId, $table, '', 'index_column:' . $row['name']);

                continue;
            }
            $events[] = new RecordIndexColumn($indexId, $position++, (string) $member['name']);
        }
    }

    return $events;
}

function quote_identifier(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}
