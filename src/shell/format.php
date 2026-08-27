<?php

declare(strict_types=1);

namespace Tlon\Shell;

function emit(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function emit_error(string $line): void
{
    fwrite(STDERR, $line . "\n");
}

function pad(string $value, int $width): string
{
    return str_pad($value, $width);
}

function emit_rows(array $rows, array $headings): void
{
    if ($rows === []) {
        emit('  (nothing)');

        return;
    }
    $widths = array_map(fn (string $h) => strlen($h), $headings);
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
        }
    }
    $line = '';
    foreach ($headings as $i => $heading) {
        $line .= pad($heading, $widths[$i] + 2);
    }
    emit('  ' . rtrim($line));
    foreach ($rows as $row) {
        $line = '';
        foreach (array_values($row) as $i => $cell) {
            $line .= pad((string) $cell, $widths[$i] + 2);
        }
        emit('  ' . rtrim($line));
    }
}
