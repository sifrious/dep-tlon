<?php

declare(strict_types=1);

require __DIR__ . '/../src/core/code.php';

use Tlon\Core\{CodeGraph, CodeReference, CodeSymbol, CodeSymbolObservation, SourceRange};

function codeAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

$graph = new CodeGraph();
$php = new CodeSymbol('sym-user', 'repo-1', 'php', 'class');
$typescript = new CodeSymbol('sym-client', 'repo-1', 'typescript', 'function');
$graph->addSymbol($php);
$graph->addSymbol($typescript);
$graph->observe(new CodeSymbolObservation('i1', 'sym-user', 'App\\User', 'src/User.php', new SourceRange(10, 1, 30, 1), ['adapter' => 'php-parser']));
$graph->observe(new CodeSymbolObservation('i1', 'sym-client', 'loadUser', 'web/client.ts', new SourceRange(4, 1, 8, 2), ['adapter' => 'typescript-lsp']));
$graph->observe(new CodeSymbolObservation('i2', 'sym-user', 'Domain\\Person', 'src/Person.php', new SourceRange(12, 1, 32, 1), ['adapter' => 'php-parser']));
$graph->observe(new CodeSymbolObservation('i2', 'sym-client', 'loadUser', 'web/client.ts', new SourceRange(4, 1, 8, 2), ['adapter' => 'typescript-lsp']));
$graph->addReference(new CodeReference('edge-1', 'i2', 'sym-client', 'sym-user', 'call', new SourceRange(6, 3, 6, 15), parserEvidence: ['confidence' => 1]));
$graph->addReference(new CodeReference('edge-2', 'i2', 'sym-user', null, 'type-use', new SourceRange(14, 5, 14, 18), 'Vendor\\MissingType'));

$report = $graph->reconcile('i1', 'i2');
codeAssert($report['renamed'] === ['sym-user'], 'rename was not detected');
codeAssert($report['moved'] === ['sym-user'], 'move was not detected');
codeAssert($report['unchanged'] === ['sym-client'], 'unchanged symbol was not retained');
codeAssert(count($graph->outbound('sym-client')) === 1, 'outbound traversal failed');
codeAssert(count($graph->inbound('sym-user')) === 1, 'inbound traversal failed');
codeAssert(! $graph->outbound('sym-user')[0]->resolved(), 'unresolved reference was dropped');
$export = $graph->export();
codeAssert(count($export['symbols']) === 2 && count($export['references']) === 2, 'machine export lost identities');
codeAssert($export['symbols'][0]['language'] === 'php' && $export['symbols'][1]['language'] === 'typescript', 'language-neutral fixtures missing');

echo "8 code graph assertions passed\n";
