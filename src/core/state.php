<?php

declare(strict_types=1);

namespace Tlon\Core;

interface SourceInterface
{
    public function id(): string;

    public function name(): string;

    public function engine(): string;
}

abstract readonly class AbstractSource implements SourceInterface
{
    public const ENGINES = ['sqlite', 'mysql', 'postgresql'];

    public function __construct(
        public string $id,
        public string $name,
        public string $engine,
        public string $connection,
        public string $registeredAt,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function engine(): string
    {
        return $this->engine;
    }
}

final readonly class Source extends AbstractSource {}

final readonly class Inspection
{
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const RUNNING = 'running';

    public function __construct(
        public string $id,
        public string $sourceId,
        public string $startedAt,
        public string $endedAt = '',
        public string $outcome = self::RUNNING,
        public string $stoppedAt = '',
        public string $failure = '',
    ) {}
}

final readonly class ObservedTable
{
    public const KINDS = ['table', 'view'];

    public function __construct(
        public string $inspectionId,
        public string $name,
        public string $kind,
        public string $description = '',
        public ?int $rowEstimate = null,
        public ?int $sizeBytes = null,
        public string $measuredAt = '',
    ) {}
}

final readonly class ObservedColumn
{
    public function __construct(
        public string $inspectionId,
        public string $tableName,
        public string $name,
        public int $position,
        public string $declaredType,
        public bool $acceptsNothing,
        public string $defaultExpression = '',
        public bool $hasDefault = false,
        public string $description = '',
    ) {}
}

final readonly class ObservedPrimaryKeyColumn
{
    public function __construct(
        public string $inspectionId,
        public string $tableName,
        public int $position,
        public string $columnName,
    ) {}
}

final readonly class ObservedReference
{
    public function __construct(
        public string $id,
        public string $inspectionId,
        public string $name,
        public string $fromTable,
        public string $toTable,
        public string $onUpdate = '',
        public string $onDelete = '',
    ) {}
}

final readonly class ObservedReferenceColumn
{
    public function __construct(
        public string $referenceId,
        public int $position,
        public string $fromColumn,
        public string $toColumn,
    ) {}
}

final readonly class ObservedIndex
{
    public function __construct(
        public string $id,
        public string $inspectionId,
        public string $tableName,
        public string $name,
        public bool $requiresUniqueness,
    ) {}
}

final readonly class ObservedIndexColumn
{
    public function __construct(
        public string $indexId,
        public int $position,
        public string $columnName,
    ) {}
}

final readonly class UnavailableDatum
{
    public function __construct(
        public string $inspectionId,
        public string $tableName,
        public string $columnName,
        public string $datum,
    ) {}
}

final readonly class Note
{
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $tableName,
        public string $columnName,
        public string $body,
        public string $writtenAt,
    ) {}
}

final readonly class State
{
    public function __construct(
        public array $sources = [],
        public array $inspections = [],
        public array $tables = [],
        public array $columns = [],
        public array $primaryKeyColumns = [],
        public array $references = [],
        public array $referenceColumns = [],
        public array $indexes = [],
        public array $indexColumns = [],
        public array $unavailable = [],
        public array $notes = [],
    ) {}

    public function with(array $changes): self
    {
        return new self(
            sources: $changes['sources'] ?? $this->sources,
            inspections: $changes['inspections'] ?? $this->inspections,
            tables: $changes['tables'] ?? $this->tables,
            columns: $changes['columns'] ?? $this->columns,
            primaryKeyColumns: $changes['primaryKeyColumns'] ?? $this->primaryKeyColumns,
            references: $changes['references'] ?? $this->references,
            referenceColumns: $changes['referenceColumns'] ?? $this->referenceColumns,
            indexes: $changes['indexes'] ?? $this->indexes,
            indexColumns: $changes['indexColumns'] ?? $this->indexColumns,
            unavailable: $changes['unavailable'] ?? $this->unavailable,
            notes: $changes['notes'] ?? $this->notes,
        );
    }

    public function withAdded(string $relation, object $row): self
    {
        return $this->with([$relation => [...$this->{$relation}, $row]]);
    }
}

function empty_state(): State
{
    return new State();
}
