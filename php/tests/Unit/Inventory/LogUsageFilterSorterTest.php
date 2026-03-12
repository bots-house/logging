<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Adheart\Logging\Inventory\Filter\LogUsageFilterSorter;
use Adheart\Logging\Inventory\Model\LogUsage;
use Adheart\Logging\Inventory\Model\ScanQuery;
use PHPUnit\Framework\TestCase;

final class LogUsageFilterSorterTest extends TestCase
{
    public function testAppliesSeverityAndSorting(): void
    {
        $usages = [
            new LogUsage(
                'b',
                'literal',
                'info',
                'payments',
                'payments',
                ['foo'],
                "['foo' => 1]",
                'src/Billing/A.php',
                10,
                'Billing'
            ),
            new LogUsage(
                'a',
                'literal',
                'dynamic',
                'unknown',
                'unknown',
                ['exception'],
                "['exception' => \$e]",
                'src/Billing/B.php',
                11,
                'Billing'
            ),
            new LogUsage(
                'c',
                'dynamic',
                'error',
                'payments',
                'payments',
                ['bar'],
                "['bar' => 1]",
                'src/User/C.php',
                9,
                'User'
            ),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: ['Billing'],
            loggerNames: ['payments', 'unknown'],
            channels: [],
            contextKeys: ['exception'],
            severityMin: 'warning',
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(1, $result);
        self::assertSame('a', $result[0]->message);
        self::assertSame('dynamic', $result[0]->severity);
    }

    public function testStrictSeverityDropsDynamicRows(): void
    {
        $usages = [
            new LogUsage('a', 'dynamic', 'dynamic', 'unknown', 'unknown', [], '', 'src/Billing/B.php', 11, 'Billing'),
            new LogUsage('b', 'literal', 'error', 'payments', 'payments', [], '', 'src/Billing/A.php', 10, 'Billing'),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: 'warning',
            onlySeverities: [],
            strictSeverity: true,
            sort: 'severity',
            order: 'desc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(1, $result);
        self::assertSame('b', $result[0]->message);
    }

    public function testFiltersByChannel(): void
    {
        $usages = [
            new LogUsage('a', 'literal', 'info', 'payments', 'payments', [], '', 'src/Billing/A.php', 10, 'Billing'),
            new LogUsage(
                'b',
                'literal',
                'info',
                'marketing',
                'marketing',
                [],
                '',
                'src/Marketing/B.php',
                11,
                'Marketing'
            ),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: ['marketing'],
            contextKeys: [],
            severityMin: 'debug',
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(1, $result);
        self::assertSame('b', $result[0]->message);
    }

    public function testFiltersByContextKeyCaseInsensitive(): void
    {
        $usages = [
            new LogUsage('a', 'literal', 'info', 'payments', 'payments', ['FooKey'], '', 'src/A.php', 1, 'Billing'),
            new LogUsage('b', 'literal', 'info', 'payments', 'payments', ['other'], '', 'src/B.php', 2, 'Billing'),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: ['fookey'],
            severityMin: 'debug',
            onlySeverities: [],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(1, $result);
        self::assertSame('a', $result[0]->message);
    }

    public function testOnlySeveritiesHonorsDynamicWhenAllowed(): void
    {
        $usages = [
            new LogUsage(
                'dynamic message',
                'dynamic',
                'dynamic',
                'logger',
                'channel',
                [],
                '',
                'src/Dynamic.php',
                1,
                'Domain'
            ),
            new LogUsage(
                'error message',
                'literal',
                'error',
                'logger',
                'channel',
                [],
                '',
                'src/Error.php',
                2,
                'Domain'
            ),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: ['dynamic'],
            strictSeverity: false,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(1, $result);
        self::assertSame('dynamic message', $result[0]->message);
    }

    public function testOnlySeveritiesStrictSeverityCanExcludeDynamic(): void
    {
        $usages = [
            new LogUsage(
                'dynamic message',
                'dynamic',
                'dynamic',
                'logger',
                'channel',
                [],
                '',
                'src/Dynamic.php',
                1,
                'Domain'
            ),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: null,
            onlySeverities: ['dynamic'],
            strictSeverity: true,
            sort: 'message',
            order: 'asc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(0, $result);
    }

    public function testSortsBySeverityDescending(): void
    {
        $usages = [
            new LogUsage('info message', 'literal', 'info', 'logger', 'channel', [], '', 'src/Info.php', 1, 'Domain'),
            new LogUsage(
                'critical message',
                'literal',
                'critical',
                'logger',
                'channel',
                [],
                '',
                'src/Critical.php',
                2,
                'Domain'
            ),
        ];

        $query = new ScanQuery(
            paths: ['src'],
            pathPrefixes: [],
            excludePathPrefixes: [],
            domainContexts: [],
            loggerNames: [],
            channels: [],
            contextKeys: [],
            severityMin: 'debug',
            onlySeverities: [],
            strictSeverity: false,
            sort: 'severity',
            order: 'desc',
            truncate: true,
            view: 'compact',
            contextMaxChars: 4000,
            onlyDynamicMessages: false,
            summary: false,
            format: 'table'
        );

        $service = new LogUsageFilterSorter();
        $result = $service->apply($query, $usages);

        self::assertCount(2, $result);
        self::assertSame('critical message', $result[0]->message);
        self::assertSame('info message', $result[1]->message);
    }
}
