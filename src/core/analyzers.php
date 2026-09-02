<?php

declare(strict_types=1);

namespace Tlon\Core;

interface SymbolIdentityProviderInterface
{
    public function id(string $repositoryId, string $language, string $kind, string $qualifiedName, string $path): string;
}

final readonly class HashSymbolIdentityProvider implements SymbolIdentityProviderInterface
{
    public function id(string $repositoryId, string $language, string $kind, string $qualifiedName, string $path): string
    {
        return 'sym-' . substr(hash('sha256', implode("\0", [$repositoryId, $language, $kind, $qualifiedName])), 0, 24);
    }
}

final readonly class MappedSymbolIdentityProvider implements SymbolIdentityProviderInterface
{
    public function __construct(private array $identities, private ?SymbolIdentityProviderInterface $fallback = null) {}

    public function id(string $repositoryId, string $language, string $kind, string $qualifiedName, string $path): string
    {
        $key = implode('|', [$language, $kind, $qualifiedName, $path]);
        return $this->identities[$key]
            ?? ($this->fallback ?? new HashSymbolIdentityProvider())->id($repositoryId, $language, $kind, $qualifiedName, $path);
    }
}

interface AnalyzerAdapterInterface
{
    public function language(): string;

    public function supports(string $path): bool;

    public function inspect(CodeGraph $graph, string $repositoryId, string $inspectionId, string $path, string $source): array;
}

abstract readonly class AbstractAnalyzerAdapter implements AnalyzerAdapterInterface
{
    public function __construct(protected SymbolIdentityProviderInterface $identities) {}

    protected function symbolId(string $repositoryId, string $kind, string $name, string $path): string
    {
        return $this->identities->id($repositoryId, $this->language(), $kind, $name, $path);
    }

    protected function referenceId(string $inspectionId, string $path, int $line, string $sourceId, string $target): string
    {
        return 'ref-' . substr(hash('sha256', implode("\0", [$inspectionId, $path, $line, $sourceId, $target])), 0, 24);
    }
}

final readonly class PhpAnalyzerAdapter extends AbstractAnalyzerAdapter
{
    public function language(): string { return 'php'; }
    public function supports(string $path): bool { return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'; }

    public function inspect(CodeGraph $graph, string $repositoryId, string $inspectionId, string $path, string $source): array
    {
        $tokens = token_get_all($source);
        $definitions = [];
        $namespace = '';
        $pendingKind = null;
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) { continue; }
            [$type, $text, $line] = $token;
            if ($type === T_NAMESPACE) {
                $namespace = $this->nextName($tokens, $index + 1);
                continue;
            }
            $pendingKind = match ($type) {
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                T_FUNCTION => 'function',
                default => null,
            };
            if ($pendingKind === null) { continue; }
            $name = $this->nextName($tokens, $index + 1);
            if ($name === '') { continue; }
            $qualified = $namespace === '' ? $name : $namespace . '\\' . $name;
            $id = $this->symbolId($repositoryId, $pendingKind, $qualified, $path);
            $symbol = new CodeSymbol($id, $repositoryId, $this->language(), $pendingKind);
            $graph->addSymbol($symbol);
            $graph->observe(new CodeSymbolObservation($inspectionId, $id, $qualified, $path, new SourceRange($line, 1, $line, max(1, strlen($text))), ['adapter' => self::class]));
            $definitions[$name] = $id;
        }
        $sourceId = reset($definitions) ?: null;
        $references = 0;
        if ($sourceId !== null) {
            foreach ($tokens as $index => $token) {
                if (! is_array($token) || $token[0] !== T_STRING || ! $this->followedByCall($tokens, $index + 1)) { continue; }
                $name = $token[1];
                if (isset($definitions[$name]) && $definitions[$name] === $sourceId) { continue; }
                $target = $definitions[$name] ?? null;
                $graph->addReference(new CodeReference(
                    $this->referenceId($inspectionId, $path, $token[2], $sourceId, $name), $inspectionId, $sourceId, $target,
                    'call', new SourceRange($token[2], 1, $token[2], max(1, strlen($name))), $target === null ? $name : '',
                    ['adapter' => self::class],
                ));
                $references++;
            }
        }
        return ['symbols' => count($definitions), 'references' => $references];
    }

    private function nextName(array $tokens, int $from): string
    {
        $name = '';
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token) && ($token === ';' || $token === '{' || $token === '(')) { break; }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) { $name .= $token[1]; }
            elseif ($name !== '' && is_array($token) && $token[0] !== T_WHITESPACE) { break; }
        }
        return $name;
    }

    private function followedByCall(array $tokens, int $from): bool
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) { continue; }
            return $tokens[$i] === '(';
        }
        return false;
    }
}

final readonly class TypeScriptAnalyzerAdapter extends AbstractAnalyzerAdapter
{
    public function language(): string { return 'typescript'; }
    public function supports(string $path): bool { return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['ts', 'tsx'], true); }

    public function inspect(CodeGraph $graph, string $repositoryId, string $inspectionId, string $path, string $source): array
    {
        $definitions = [];
        foreach (preg_split('/\R/', $source) as $offset => $line) {
            if (! preg_match('/\b(class|interface|function)\s+([A-Za-z_$][A-Za-z0-9_$]*)/', $line, $match, PREG_OFFSET_CAPTURE)) { continue; }
            $kind = $match[1][0]; $name = $match[2][0]; $lineNumber = $offset + 1;
            $id = $this->symbolId($repositoryId, $kind, $name, $path);
            $graph->addSymbol(new CodeSymbol($id, $repositoryId, $this->language(), $kind));
            $graph->observe(new CodeSymbolObservation($inspectionId, $id, $name, $path, new SourceRange($lineNumber, $match[2][1] + 1, $lineNumber, $match[2][1] + strlen($name) + 1), ['adapter' => self::class]));
            $definitions[$name] = $id;
        }
        $sourceId = reset($definitions) ?: null; $references = 0;
        if ($sourceId !== null) {
            foreach (preg_split('/\R/', $source) as $offset => $line) {
                preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $line, $calls, PREG_OFFSET_CAPTURE);
                foreach ($calls[1] as [$name, $column]) {
                    if (in_array($name, ['if', 'for', 'while', 'switch', 'function'], true) || ($definitions[$name] ?? null) === $sourceId) { continue; }
                    $target = $definitions[$name] ?? null;
                    $graph->addReference(new CodeReference(
                        $this->referenceId($inspectionId, $path, $offset + 1, $sourceId, $name), $inspectionId, $sourceId, $target,
                        'call', new SourceRange($offset + 1, $column + 1, $offset + 1, $column + strlen($name) + 1), $target === null ? $name : '',
                        ['adapter' => self::class],
                    ));
                    $references++;
                }
            }
        }
        return ['symbols' => count($definitions), 'references' => $references];
    }
}

final readonly class CodeInspectionService
{
    public function __construct(private array $adapters) {}

    public function inspectFiles(CodeGraph $graph, string $repositoryId, string $inspectionId, array $paths): array
    {
        $summary = ['files' => 0, 'symbols' => 0, 'references' => 0, 'unsupported' => []];
        foreach ($paths as $path) {
            $adapter = null;
            foreach ($this->adapters as $candidate) { if ($candidate->supports($path)) { $adapter = $candidate; break; } }
            if ($adapter === null) { $summary['unsupported'][] = $path; continue; }
            $result = $adapter->inspect($graph, $repositoryId, $inspectionId, $path, (string) file_get_contents($path));
            $summary['files']++; $summary['symbols'] += $result['symbols']; $summary['references'] += $result['references'];
        }
        return $summary;
    }
}
