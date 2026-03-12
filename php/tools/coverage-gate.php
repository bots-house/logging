#!/usr/bin/env php
<?php
// phpcs:ignoreFile -- CLI entrypoint intentionally contains executable logic and helper functions.

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php php/tools/coverage-gate.php <clover.xml>\n");
    exit(2);
}

$cloverPath = $argv[1];
if (!is_file($cloverPath)) {
    fwrite(STDERR, "Clover file not found: {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Cannot parse clover XML: {$cloverPath}\n");
    exit(2);
}

/** @var list<array{path:string, statements:int, covered:int, uncovered:int, coverage:float}> $files */
$files = [];
$fileNodes = $xml->xpath('//file');
if (!is_array($fileNodes)) {
    fwrite(STDERR, "No file nodes found in clover report.\n");
    exit(2);
}

foreach ($fileNodes as $fileNode) {
    $nameAttr = (string)($fileNode['name'] ?? '');
    if ($nameAttr === '') {
        continue;
    }

    $metrics = $fileNode->metrics;
    $statements = (int)($metrics['statements'] ?? 0);
    $covered = (int)($metrics['coveredstatements'] ?? 0);
    if ($statements <= 0) {
        continue;
    }

    $uncovered = max(0, $statements - $covered);
    $coverage = ((float)$covered / (float)$statements) * 100.0;
    $files[] = [
        'path' => normalizePath($nameAttr),
        'statements' => $statements,
        'covered' => $covered,
        'uncovered' => $uncovered,
        'coverage' => $coverage,
    ];
}

if ($files === []) {
    fwrite(STDERR, "No file metrics found in clover report.\n");
    exit(2);
}

$overall = aggregate($files, static fn (array $file): bool => true);
$core = aggregate($files, static fn (array $file): bool => pathContains($file['path'], '/php/src/Core/'));
$di = aggregate($files, static fn (array $file): bool => pathContains($file['path'], '/php/src/DependencyInjection/'));

$criticalPredicates = [
    'php/src/LoggingBundle.php' => static fn (array $file): bool => pathEndsWith(
        $file['path'],
        '/php/src/LoggingBundle.php'
    ),
    'php/src/DependencyInjection/Compiler/*' => static fn (array $file): bool => pathContains(
        $file['path'],
        '/php/src/DependencyInjection/Compiler/'
    ),
    'php/src/Core/Formatters/SchemaFormatterV1.php' => static fn (array $file): bool => pathEndsWith(
        $file['path'],
        '/php/src/Core/Formatters/SchemaFormatterV1.php'
    ),
    'php/src/Integration/OpenTelemetry/Trace/OpenTelemetryTraceContextProvider.php'
        => static fn (array $file): bool => pathEndsWith(
            $file['path'],
            '/php/src/Integration/OpenTelemetry/Trace/OpenTelemetryTraceContextProvider.php'
        ),
];

$criticalResults = [];
foreach ($criticalPredicates as $name => $predicate) {
    $criticalResults[$name] = aggregate($files, $predicate);
}

$failures = [];
assertThreshold('Core namespace lines', $core, 95.0, $failures);
assertThreshold('DependencyInjection namespace lines', $di, 95.0, $failures);
foreach ($criticalResults as $name => $result) {
    assertThreshold("Critical {$name} lines", $result, 95.0, $failures);
}
assertThreshold('Overall lines', $overall, 88.0, $failures);

echo "Coverage Gate Summary\n";
echo "Overall: " . formatAggregate($overall) . "\n";
echo "Core namespace: " . formatAggregate($core) . "\n";
echo "DependencyInjection namespace: " . formatAggregate($di) . "\n";
foreach ($criticalResults as $name => $result) {
    echo "Critical {$name}: " . formatAggregate($result) . "\n";
}
echo "\n";

printTopUncoveredByCoverage($files, 10);
echo "\n";
printTopUncoveredByCount($files, 10);
echo "\n";

if ($failures !== []) {
    echo "Coverage gate failed:\n";
    foreach ($failures as $failure) {
        echo "- {$failure}\n";
    }
    exit(1);
}

echo "Coverage gate passed.\n";

/**
 * @param list<array{path:string, statements:int, covered:int, uncovered:int, coverage:float}> $files
 *
 * @return array{statements:int, covered:int, uncovered:int, coverage:float}|null
 */
function aggregate(array $files, callable $predicate): ?array
{
    $statements = 0;
    $covered = 0;

    foreach ($files as $file) {
        if (!$predicate($file)) {
            continue;
        }

        $statements += $file['statements'];
        $covered += $file['covered'];
    }

    if ($statements === 0) {
        return null;
    }

    return [
        'statements' => $statements,
        'covered' => $covered,
        'uncovered' => max(0, $statements - $covered),
        'coverage' => ((float)$covered / (float)$statements) * 100.0,
    ];
}

/**
 * @param array<string> $failures
 * @param array{statements:int, covered:int, uncovered:int, coverage:float}|null $aggregate
 */
function assertThreshold(string $label, ?array $aggregate, float $threshold, array &$failures): void
{
    if ($aggregate === null) {
        $failures[] = "{$label}: no statements found";
        return;
    }

    if ($aggregate['coverage'] < $threshold) {
        $failures[] = sprintf(
            '%s %.2f%% is below %.2f%% (%d/%d lines covered)',
            $label,
            $aggregate['coverage'],
            $threshold,
            $aggregate['covered'],
            $aggregate['statements']
        );
    }
}

/**
 * @param array{statements:int, covered:int, uncovered:int, coverage:float}|null $aggregate
 */
function formatAggregate(?array $aggregate): string
{
    if ($aggregate === null) {
        return 'n/a';
    }

    return sprintf(
        '%.2f%% (%d/%d, uncovered %d)',
        $aggregate['coverage'],
        $aggregate['covered'],
        $aggregate['statements'],
        $aggregate['uncovered']
    );
}

/**
 * @param list<array{path:string, statements:int, covered:int, uncovered:int, coverage:float}> $files
 */
function printTopUncoveredByCoverage(array $files, int $limit): void
{
    $uncovered = array_values(array_filter($files, static fn (array $file): bool => $file['uncovered'] > 0));

    usort($uncovered, static function (array $left, array $right): int {
        $coverageCmp = $left['coverage'] <=> $right['coverage'];
        if ($coverageCmp !== 0) {
            return $coverageCmp;
        }

        return $right['uncovered'] <=> $left['uncovered'];
    });

    echo "Top uncovered files by line coverage:\n";
    if ($uncovered === []) {
        echo "- none\n";
        return;
    }

    foreach (array_slice($uncovered, 0, $limit) as $file) {
        echo sprintf(
            "- %.2f%% (%d/%d, uncovered %d) %s\n",
            $file['coverage'],
            $file['covered'],
            $file['statements'],
            $file['uncovered'],
            toRepoRelativePath($file['path'])
        );
    }
}

/**
 * @param list<array{path:string, statements:int, covered:int, uncovered:int, coverage:float}> $files
 */
function printTopUncoveredByCount(array $files, int $limit): void
{
    $uncovered = array_values(array_filter($files, static fn (array $file): bool => $file['uncovered'] > 0));

    usort($uncovered, static function (array $left, array $right): int {
        $countCmp = $right['uncovered'] <=> $left['uncovered'];
        if ($countCmp !== 0) {
            return $countCmp;
        }

        return $left['coverage'] <=> $right['coverage'];
    });

    echo "Top files by uncovered lines count:\n";
    if ($uncovered === []) {
        echo "- none\n";
        return;
    }

    foreach (array_slice($uncovered, 0, $limit) as $file) {
        echo sprintf(
            "- uncovered %d (%.2f%%, %d/%d) %s\n",
            $file['uncovered'],
            $file['coverage'],
            $file['covered'],
            $file['statements'],
            toRepoRelativePath($file['path'])
        );
    }
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function pathContains(string $path, string $needle): bool
{
    return str_contains(canonicalPath($path), canonicalPath($needle));
}

function pathEndsWith(string $path, string $suffix): bool
{
    return str_ends_with(canonicalPath($path), canonicalPath($suffix));
}

function toRepoRelativePath(string $absolutePath): string
{
    $path = normalizePath($absolutePath);
    $marker = '/php/';
    $position = strpos($path, $marker);
    if ($position === false) {
        return $path;
    }

    return ltrim(substr($path, $position + 1), '/');
}

function canonicalPath(string $path): string
{
    return '/' . ltrim(normalizePath($path), '/');
}
