<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Adheart\Logging\Inventory\ScanQueryFactory;
use Adheart\Logging\Inventory\Model\ScanQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class ScanQueryFactoryTest extends TestCase
{
    public function testParseCsvListNormalizesPaths(): void
    {
        $factory = new ScanQueryFactory();

        $result = $factory->parseCsvList(' /foo/bar , \\baz\\ , , foo ');

        self::assertSame(['foo/bar', 'baz', 'foo'], $result);
    }

    public function testBuildConstructsScanQueryWithNormalizedOptions(): void
    {
        $input = new ArrayInput([
            '--paths' => ['src', 'src', '\\var\\logs'],
            '--path-prefix' => ' \\src\\base ',
            '--exclude-path-prefix' => ['vendor/', 'node_modules'],
            '--domain-context' => 'Billing, Sales ',
            '--logger-name' => 'app,custom',
            '--channel' => 'Main,Side',
            '--context-key' => 'Foo,bar',
            '--severity-min' => ' WARNING ',
            '--only-severity' => 'error,DEBUG',
            '--strict-severity' => true,
            '--sort' => ' path ',
            '--order' => ' desc ',
            '--format' => ' json ',
            '--view' => ' compact ',
            '--context-max-chars' => '5',
            '--no-truncate' => true,
            '--only-dynamic-messages' => true,
            '--summary' => true,
        ], $this->inputDefinition());

        $factory = new ScanQueryFactory();
        $query = $factory->build($input);

        self::assertInstanceOf(ScanQuery::class, $query);
        self::assertSame(['src', 'var/logs'], $query->paths);
        self::assertSame(['src/base'], $query->pathPrefixes);
        self::assertSame(['vendor', 'node_modules'], $query->excludePathPrefixes);
        self::assertSame(['Billing', 'Sales'], $query->domainContexts);
        self::assertSame(['app', 'custom'], $query->loggerNames);
        self::assertSame(['main', 'side'], $query->channels);
        self::assertSame(['foo', 'bar'], $query->contextKeys);
        self::assertSame('warning', $query->severityMin);
        self::assertSame(['error', 'debug'], $query->onlySeverities);
        self::assertTrue($query->strictSeverity);
        self::assertSame('path', $query->sort);
        self::assertSame('desc', $query->order);
        self::assertFalse($query->truncate);
        self::assertSame('compact', $query->view);
        self::assertSame(5, $query->contextMaxChars);
        self::assertTrue($query->onlyDynamicMessages);
        self::assertTrue($query->summary);
        self::assertSame('json', $query->format);
    }

    private function inputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputOption('paths', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('path-prefix', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('exclude-path-prefix', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('domain-context', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('logger-name', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('channel', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('context-key', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('only-severity', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('severity-min', null, InputOption::VALUE_OPTIONAL),
            new InputOption('sort', null, InputOption::VALUE_OPTIONAL),
            new InputOption('order', null, InputOption::VALUE_OPTIONAL),
            new InputOption('format', null, InputOption::VALUE_OPTIONAL),
            new InputOption('view', null, InputOption::VALUE_OPTIONAL),
            new InputOption('context-max-chars', null, InputOption::VALUE_OPTIONAL),
            new InputOption('strict-severity', null, InputOption::VALUE_NONE),
            new InputOption('no-truncate', null, InputOption::VALUE_NONE),
            new InputOption('only-dynamic-messages', null, InputOption::VALUE_NONE),
            new InputOption('summary', null, InputOption::VALUE_NONE),
        ]);
    }
}
