<?php

declare(strict_types=1);

namespace Tlon\Core;

interface CodeSymbolInterface
{
    public function stableId(): string;

    public function repositoryId(): string;

    public function language(): string;

    public function kind(): string;
}

abstract readonly class AbstractCodeSymbol implements CodeSymbolInterface
{
    public function __construct(
        private string $stableId,
        private string $repositoryId,
        private string $language,
        public string $kind,
    ) {
        if ($stableId === '' || $repositoryId === '' || $language === '' || $kind === '') {
            throw new \InvalidArgumentException('Symbol identity fields cannot be empty.');
        }
    }

    public function stableId(): string { return $this->stableId; }
    public function repositoryId(): string { return $this->repositoryId; }
    public function language(): string { return $this->language; }
    public function kind(): string { return $this->kind; }
}

final readonly class CodeSymbol extends AbstractCodeSymbol {}

final readonly class SourceRange
{
    public function __construct(
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
    ) {
        if ($startLine < 1 || $startColumn < 1 || $endLine < $startLine || $endColumn < 1) {
            throw new \InvalidArgumentException('Invalid one-based source range.');
        }
    }

    public function export(): array
    {
        return get_object_vars($this);
    }
}

final readonly class CodeSymbolObservation
{
    public function __construct(
        public string $inspectionId,
        public string $symbolId,
        public string $qualifiedName,
        public string $path,
        public SourceRange $range,
        public array $parserEvidence = [],
    ) {
        if ($inspectionId === '' || $symbolId === '' || $qualifiedName === '' || $path === '') {
            throw new \InvalidArgumentException('Observation identity and location fields cannot be empty.');
        }
    }

    public function export(): array
    {
        return [
            'inspectionId' => $this->inspectionId,
            'symbolId' => $this->symbolId,
            'qualifiedName' => $this->qualifiedName,
            'path' => $this->path,
            'range' => $this->range->export(),
            'parserEvidence' => $this->parserEvidence,
        ];
    }
}

final readonly class CodeReference
{
    public function __construct(
        public string $id,
        public string $inspectionId,
        public string $sourceSymbolId,
        public ?string $targetSymbolId,
        public string $kind,
        public SourceRange $sourceRange,
        public string $externalTarget = '',
        public array $parserEvidence = [],
    ) {
        if ($id === '' || $inspectionId === '' || $sourceSymbolId === '' || $kind === '') {
            throw new \InvalidArgumentException('Reference identity fields cannot be empty.');
        }
        if (($targetSymbolId === null) === ($externalTarget === '')) {
            throw new \InvalidArgumentException('A reference needs exactly one canonical or unresolved external target.');
        }
    }

    public function resolved(): bool { return $this->targetSymbolId !== null; }

    public function export(): array
    {
        return [
            'id' => $this->id,
            'inspectionId' => $this->inspectionId,
            'sourceSymbolId' => $this->sourceSymbolId,
            'targetSymbolId' => $this->targetSymbolId,
            'externalTarget' => $this->externalTarget,
            'kind' => $this->kind,
            'sourceRange' => $this->sourceRange->export(),
            'parserEvidence' => $this->parserEvidence,
        ];
    }
}

final class CodeGraph
{
    private array $symbols = [];
    private array $observations = [];
    private array $references = [];

    public static function fromExport(array $data): self
    {
        $graph = new self();
        foreach ($data['symbols'] ?? [] as $row) {
            $graph->addSymbol(new CodeSymbol($row['id'], $row['repositoryId'], $row['language'], $row['kind']));
        }
        foreach ($data['observations'] ?? [] as $row) {
            $range = $row['range'];
            $graph->observe(new CodeSymbolObservation(
                $row['inspectionId'], $row['symbolId'], $row['qualifiedName'], $row['path'],
                new SourceRange($range['startLine'], $range['startColumn'], $range['endLine'], $range['endColumn']),
                $row['parserEvidence'] ?? [],
            ));
        }
        foreach ($data['references'] ?? [] as $row) {
            $range = $row['sourceRange'];
            $graph->addReference(new CodeReference(
                $row['id'], $row['inspectionId'], $row['sourceSymbolId'], $row['targetSymbolId'], $row['kind'],
                new SourceRange($range['startLine'], $range['startColumn'], $range['endLine'], $range['endColumn']),
                $row['externalTarget'] ?? '', $row['parserEvidence'] ?? [],
            ));
        }
        return $graph;
    }

    public function addSymbol(CodeSymbolInterface $symbol): void
    {
        $id = $symbol->stableId();
        if (isset($this->symbols[$id]) && $this->symbols[$id] != $symbol) {
            throw new \DomainException("Symbol identity collision: {$id}");
        }
        $this->symbols[$id] = $symbol;
    }

    public function observe(CodeSymbolObservation $observation): void
    {
        if (! isset($this->symbols[$observation->symbolId])) {
            throw new \DomainException('An observation needs a registered symbol.');
        }
        $this->observations[$observation->inspectionId][$observation->symbolId] = $observation;
    }

    public function addReference(CodeReference $reference): void
    {
        if (! isset($this->symbols[$reference->sourceSymbolId])) {
            throw new \DomainException('A reference source needs a registered symbol.');
        }
        if ($reference->targetSymbolId !== null && ! isset($this->symbols[$reference->targetSymbolId])) {
            throw new \DomainException('A resolved reference target needs a registered symbol.');
        }
        $this->references[$reference->id] = $reference;
    }

    public function outbound(string $symbolId): array
    {
        return array_values(array_filter($this->references, fn (CodeReference $r) => $r->sourceSymbolId === $symbolId));
    }

    public function inbound(string $symbolId): array
    {
        return array_values(array_filter($this->references, fn (CodeReference $r) => $r->targetSymbolId === $symbolId));
    }

    public function reconcile(string $beforeInspection, string $afterInspection): array
    {
        $before = $this->observations[$beforeInspection] ?? [];
        $after = $this->observations[$afterInspection] ?? [];
        $report = ['unchanged' => [], 'moved' => [], 'renamed' => [], 'added' => [], 'removed' => []];
        foreach (array_diff_key($after, $before) as $id => $_) { $report['added'][] = $id; }
        foreach (array_diff_key($before, $after) as $id => $_) { $report['removed'][] = $id; }
        foreach (array_intersect_key($after, $before) as $id => $current) {
            $prior = $before[$id];
            if ($prior->qualifiedName !== $current->qualifiedName) { $report['renamed'][] = $id; }
            if ($prior->path !== $current->path || $prior->range != $current->range) { $report['moved'][] = $id; }
            if ($prior->qualifiedName === $current->qualifiedName && $prior->path === $current->path && $prior->range == $current->range) {
                $report['unchanged'][] = $id;
            }
        }
        return $report;
    }

    public function export(): array
    {
        $observations = [];
        foreach ($this->observations as $inspection) {
            foreach ($inspection as $observation) {
                $observations[] = $observation->export();
            }
        }

        return [
            'symbols' => array_map(fn (CodeSymbolInterface $s) => [
                'id' => $s->stableId(), 'repositoryId' => $s->repositoryId(),
                'language' => $s->language(), 'kind' => $s->kind(),
            ], array_values($this->symbols)),
            'observations' => $observations,
            'references' => array_map(fn (CodeReference $r) => $r->export(), array_values($this->references)),
        ];
    }
}

final readonly class CodeGraphJsonStore
{
    public function __construct(public string $path) {}

    public function save(CodeGraph $graph): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Cannot create graph directory: {$directory}");
        }
        $json = json_encode($graph->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Cannot persist code graph: {$this->path}");
        }
    }

    public function load(): CodeGraph
    {
        if (! is_file($this->path)) { return new CodeGraph(); }
        return CodeGraph::fromExport(json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR));
    }
}
