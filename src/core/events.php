<?php

declare(strict_types=1);

namespace Tlon\Core;

final readonly class RegisterSource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $engine,
        public string $connection,
    ) {}
}

final readonly class RemoveSource
{
    public function __construct(public string $sourceId) {}
}

final readonly class StartInspection
{
    public function __construct(
        public string $id,
        public string $sourceId,
    ) {}
}

final readonly class RecordTable
{
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

final readonly class RecordColumn
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

final readonly class RecordPrimaryKeyColumn
{
    public function __construct(
        public string $inspectionId,
        public string $tableName,
        public int $position,
        public string $columnName,
    ) {}
}

final readonly class RecordReference
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

final readonly class RecordReferenceColumn
{
    public function __construct(
        public string $referenceId,
        public int $position,
        public string $fromColumn,
        public string $toColumn,
    ) {}
}

final readonly class RecordIndex
{
    public function __construct(
        public string $id,
        public string $inspectionId,
        public string $tableName,
        public string $name,
        public bool $requiresUniqueness,
    ) {}
}

final readonly class RecordIndexColumn
{
    public function __construct(
        public string $indexId,
        public int $position,
        public string $columnName,
    ) {}
}

final readonly class RecordUnavailable
{
    public function __construct(
        public string $inspectionId,
        public string $tableName,
        public string $columnName,
        public string $datum,
    ) {}
}

final readonly class CompleteInspection
{
    public function __construct(public string $inspectionId) {}
}

final readonly class FailInspection
{
    public function __construct(
        public string $inspectionId,
        public string $stoppedAt,
        public string $failure,
    ) {}
}

final readonly class AddNote
{
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $tableName,
        public string $columnName,
        public string $body,
    ) {}
}

final readonly class Recorded
{
    public function __construct(
        public string $what,
        public array $detail = [],
    ) {}
}

final readonly class Rejected
{
    public function __construct(
        public string $code,
        public string $reason,
    ) {}
}

final readonly class StepResult
{
    public function __construct(
        public State $state,
        public array $actions,
    ) {}
}
