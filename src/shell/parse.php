<?php

declare(strict_types=1);

namespace Tlon\Shell;

use Tlon\Core\{AddNote, RegisterSource, RemoveSource, State, StartInspection};

use function Tlon\Core\find_source_by_name;

function parse_register(State $state, array $args): array
{
    return [new RegisterSource(next_id($state, 'sources', 's'), $args[0] ?? '', $args[1] ?? '', $args[2] ?? '')];
}

function parse_remove(State $state, array $args): array
{
    return [new RemoveSource(source_id($state, $args[0] ?? ''))];
}

function parse_note(State $state, array $args): array
{
    $column = ($args[2] ?? '-') === '-' ? '' : (string) $args[2];

    return [new AddNote(
        next_id($state, 'notes', 'n'),
        source_id($state, $args[0] ?? ''),
        $args[1] ?? '',
        $column,
        implode(' ', array_slice($args, 3)),
    )];
}

function parse_start_inspection(State $state, array $args): array
{
    return [new StartInspection(next_id($state, 'inspections', 'i'), source_id($state, $args[0] ?? ''))];
}

function source_id(State $state, string $name): string
{
    return find_source_by_name($state, $name)?->id ?? '';
}

function source_connection(State $state, string $name): string
{
    return find_source_by_name($state, $name)?->connection ?? '';
}
