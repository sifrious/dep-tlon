<?php

declare(strict_types=1);

require __DIR__ . '/../src/core/code.php';
require __DIR__ . '/../src/core/analyzers.php';

use Tlon\Core\{CodeGraph, CodeGraphJsonStore, CodeInspectionService, HashSymbolIdentityProvider, MappedSymbolIdentityProvider, PhpAnalyzerAdapter, TypeScriptAnalyzerAdapter};

function analyzerAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

$identity = new HashSymbolIdentityProvider();
$service = new CodeInspectionService([
    new PhpAnalyzerAdapter($identity),
    new TypeScriptAnalyzerAdapter($identity),
]);
$graph = new CodeGraph();
$summary = $service->inspectFiles($graph, 'repo-fixture', 'inspection-1', [
    __DIR__ . '/fixtures/Sample.php',
    __DIR__ . '/fixtures/sample.ts',
]);

analyzerAssert($summary['files'] === 2, 'both real adapter fixtures must be inspected');
analyzerAssert($summary['symbols'] >= 4, 'PHP and TypeScript symbols were not extracted');
analyzerAssert($summary['references'] >= 2, 'call references were not extracted');
analyzerAssert($summary['unsupported'] === [], 'supported files were reported unsupported');

$export = $graph->export();
analyzerAssert(in_array('php', array_column($export['symbols'], 'language'), true), 'PHP adapter output missing');
analyzerAssert(in_array('typescript', array_column($export['symbols'], 'language'), true), 'TypeScript adapter output missing');
analyzerAssert(count(array_filter($export['references'], fn (array $r) => $r['targetSymbolId'] === null)) >= 1, 'unresolved targets were dropped');

$path = tempnam(sys_get_temp_dir(), 'tlon-code-');
$store = new CodeGraphJsonStore($path);
$store->save($graph);
$restored = $store->load();
analyzerAssert($restored->export() === $export, 'durable graph round trip changed semantic data');
unlink($path);

$mapped = new MappedSymbolIdentityProvider([
    'php|class|Fixture\\Renamed|src/Renamed.php' => 'sym-stable-across-rename',
]);
analyzerAssert(
    $mapped->id('repo-fixture', 'php', 'class', 'Fixture\\Renamed', 'src/Renamed.php') === 'sym-stable-across-rename',
    'a prior identity map cannot preserve identity through rename/move',
);

echo "9 analyzer and persistence assertions passed\n";
