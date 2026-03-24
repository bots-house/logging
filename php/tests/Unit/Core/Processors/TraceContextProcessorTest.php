<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Processors;

use Adheart\Logging\Core\Processors\TraceContextProcessor;
use Adheart\Logging\Core\Trace\TraceContextProviderInterface;
use PHPUnit\Framework\TestCase;

final class TraceContextProcessorTest extends TestCase
{
    public function testMergesTraceDataFromProvidersWithoutOverridingExistingKeys(): void
    {
        $processor = new TraceContextProcessor([
            new class () implements TraceContextProviderInterface {
                #[\Override]
                public function provide(): array
                {
                    return [
                        'trace_id' => 'provider-trace-id',
                        'span_id' => 'provider-span-id',
                    ];
                }
            },
            new class () implements TraceContextProviderInterface {
                #[\Override]
                public function provide(): array
                {
                    return [
                        'sampled' => '01',
                        'traceparent' => '00-provider-trace-id-provider-span-id-01',
                    ];
                }
            },
        ]);

        $result = $processor([
            'extra' => [
                'trace' => [
                    'trace_id' => 'existing-trace-id',
                ],
            ],
        ]);

        self::assertSame('existing-trace-id', $result['extra']['trace']['trace_id']);
        self::assertSame('provider-span-id', $result['extra']['trace']['span_id']);
        self::assertSame('01', $result['extra']['trace']['sampled']);
        self::assertSame('00-provider-trace-id-provider-span-id-01', $result['extra']['trace']['traceparent']);
    }

    public function testUsesEmptyTraceWhenCurrentTracePayloadIsNotArray(): void
    {
        $processor = new TraceContextProcessor([
            new class () implements TraceContextProviderInterface {
                #[\Override]
                public function provide(): array
                {
                    return ['trace_id' => 'new-trace-id'];
                }
            },
        ]);

        $result = $processor([
            'extra' => [
                'trace' => 'invalid',
            ],
        ]);

        self::assertSame(['trace_id' => 'new-trace-id'], $result['extra']['trace']);
    }

    public function testSupportsMonolog3StyleRecordObject(): void
    {
        $processor = new TraceContextProcessor([
            new class () implements TraceContextProviderInterface {
                #[\Override]
                public function provide(): array
                {
                    return [
                        'trace_id' => 'provider-trace-id',
                        'span_id' => 'provider-span-id',
                    ];
                }
            },
        ]);

        $record = new class (['trace' => ['trace_id' => 'existing-trace-id']]) {
            public function __construct(
                public array $extra,
            ) {
            }

            public function with(?array $extra = null): self
            {
                return new self($extra ?? $this->extra);
            }
        };

        $result = $processor($record);

        self::assertNotSame($record, $result);
        self::assertSame('existing-trace-id', $result->extra['trace']['trace_id']);
        self::assertSame('provider-span-id', $result->extra['trace']['span_id']);
    }
}
