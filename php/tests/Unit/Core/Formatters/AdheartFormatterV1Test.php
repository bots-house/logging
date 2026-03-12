<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Formatters;

use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use PHPUnit\Framework\TestCase;

final class AdheartFormatterV1Test extends TestCase
{
    public function testUsesServiceFromContextAndRemovesServiceFromContextAndExtra(): void
    {
        $formatter = new SchemaFormatterV1('1.1.0', 'fallback-service', 'fallback-version');

        $record = [
            'message' => 'event',
            'context' => [
                'service' => [
                    'name' => 'from-context',
                    'version' => 'v1',
                ],
            ],
            'extra' => [
                'service' => [
                    'name' => 'from-extra',
                ],
                'foo' => 'bar',
            ],
            'channel' => 'app',
            'level' => 200,
            'level_name' => 'INFO',
            'datetime' => new \DateTimeImmutable('2026-03-11T13:17:19.308Z'),
        ];

        $decoded = json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('from-context', $decoded['service']['name']);
        self::assertSame('v1', $decoded['service']['version']);
        self::assertArrayNotHasKey('service', $decoded['context']);
        self::assertSame(
            [
                'service' => ['name' => 'from-extra'],
                'foo' => 'bar',
            ],
            $decoded['context']['extra']
        );
    }

    public function testUsesFallbackServiceWhenServiceObjectIsMissing(): void
    {
        $formatter = new SchemaFormatterV1('1.2.3', 'billing-api', '2026.03');

        $record = [
            'message' => 'test',
            'context' => [],
            'extra' => [],
            'channel' => 'payments',
            'level' => 400,
            'level_name' => 'ERROR',
            'datetime' => new \DateTimeImmutable('2026-03-11T13:17:19.308Z'),
        ];

        $decoded = json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                'name' => 'billing-api',
                'version' => '2026.03',
                'channel' => 'payments',
            ],
            $decoded['service']
        );
        self::assertSame('1.2.3', $decoded['version']);
    }

    public function testKeepsContextTraceFramesAndUsesTraceContextFromExtra(): void
    {
        $formatter = new SchemaFormatterV1('1.0');

        $record = [
            'message' => 'test',
            'context' => [
                'trace' => [
                    ['file' => '/app/src/Foo.php', 'line' => 10],
                    ['file' => '/app/src/Bar.php', 'line' => 20],
                ],
            ],
            'extra' => [
                'trace' => [
                    'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                    'span_id' => '04c52f40924f575e',
                    'sampled' => '01',
                    'traceparent' => '00-8b730fe79387fd9b697d5563c0712d87-04c52f40924f575e-01',
                ],
            ],
            'channel' => 'app',
            'level' => 300,
            'level_name' => 'WARNING',
            'datetime' => new \DateTimeImmutable('2026-03-11T13:17:19.308Z'),
        ];

        $decoded = json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('8b730fe79387fd9b697d5563c0712d87', $decoded['trace']['trace_id']);
        self::assertSame('04c52f40924f575e', $decoded['trace']['span_id']);

        self::assertArrayHasKey('trace', $decoded['context']);
        self::assertSame('/app/src/Foo.php', $decoded['context']['trace'][0]['file']);
        self::assertSame([], $decoded['context']['extra']);
    }

    public function testPrefersContextTraceWhenItLooksLikeTraceContext(): void
    {
        $formatter = new SchemaFormatterV1();

        $record = [
            'message' => 'trace',
            'context' => [
                'trace' => [
                    'trace_id' => 'context-trace-id',
                    'span_id' => 'context-span-id',
                ],
            ],
            'extra' => [
                'trace' => [
                    'trace_id' => 'extra-trace-id',
                ],
            ],
            'extra_key' => 'ignored',
            'channel' => 'app',
            'level' => 200,
            'level_name' => 'INFO',
            'datetime' => new \DateTimeImmutable('2026-03-11T13:17:19.308Z'),
        ];

        $decoded = json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('context-trace-id', $decoded['trace']['trace_id']);
        self::assertSame('context-span-id', $decoded['trace']['span_id']);
        self::assertSame([], $decoded['context']['extra']);
    }

    public function testLeavesTraceEmptyWhenNoTraceContextPayloadExists(): void
    {
        $formatter = new SchemaFormatterV1();

        $record = [
            'message' => 'trace',
            'context' => [
                'trace' => [
                    ['file' => '/tmp/a.php', 'line' => 1],
                ],
            ],
            'extra' => [
                'trace' => [
                    ['file' => '/tmp/b.php', 'line' => 2],
                ],
                'foo' => 'bar',
            ],
            'channel' => 'app',
            'level' => 200,
            'level_name' => 'INFO',
            'datetime' => new \DateTimeImmutable('2026-03-11T13:17:19.308Z'),
        ];

        $decoded = json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['trace']);
        self::assertSame('/tmp/a.php', $decoded['context']['trace'][0]['file']);
        self::assertSame('/tmp/b.php', $decoded['context']['extra']['trace'][0]['file']);
        self::assertSame('bar', $decoded['context']['extra']['foo']);
    }

    public function testBuildsUtcTimestampWhenDatetimeIsMissingAndCanSkipTrailingNewline(): void
    {
        $formatter = new SchemaFormatterV1('1.0.0', null, null, SchemaFormatterV1::BATCH_MODE_JSON, false);

        $record = [
            'message' => 'no-datetime',
            'context' => [],
            'extra' => [],
            'channel' => 'app',
            'level' => 100,
            'level_name' => 'DEBUG',
        ];

        $json = $formatter->format($record);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString("\n", $json);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $decoded['timestamp']
        );
    }
}
