<?php

declare(strict_types=1);

namespace Tlon\Shell;

use Tlon\Core\State;

use function Tlon\Core\empty_state;

const RELATION_CLASSES = [
    'sources' => \Tlon\Core\Source::class,
    'inspections' => \Tlon\Core\Inspection::class,
    'tables' => \Tlon\Core\ObservedTable::class,
    'columns' => \Tlon\Core\ObservedColumn::class,
    'primaryKeyColumns' => \Tlon\Core\ObservedPrimaryKeyColumn::class,
    'references' => \Tlon\Core\ObservedReference::class,
    'referenceColumns' => \Tlon\Core\ObservedReferenceColumn::class,
    'indexes' => \Tlon\Core\ObservedIndex::class,
    'indexColumns' => \Tlon\Core\ObservedIndexColumn::class,
    'unavailable' => \Tlon\Core\UnavailableDatum::class,
    'notes' => \Tlon\Core\Note::class,
];

function state_path(): string
{
    return getenv('TLON_STATE') ?: getcwd() . '/state.json';
}

function load_state(string $path): State
{
    if (! file_exists($path)) {
        return empty_state();
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (! is_array($raw)) {
        return empty_state();
    }
    $relations = [];
    foreach (RELATION_CLASSES as $relation => $class) {
        $relations[$relation] = array_map(
            fn (array $row) => new $class(...$row),
            $raw[$relation] ?? [],
        );
    }

    return empty_state()->with($relations);
}

function save_state(State $state, string $path): void
{
    $raw = [];
    foreach (array_keys(RELATION_CLASSES) as $relation) {
        $raw[$relation] = array_map(fn (object $row) => (array) $row, $state->{$relation});
    }
    $temp = $path . '.tmp';
    file_put_contents($temp, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($temp, $path);
}

function next_id(State $state, string $relation, string $prefix): string
{
    return $prefix . (count($state->{$relation}) + 1);
}
