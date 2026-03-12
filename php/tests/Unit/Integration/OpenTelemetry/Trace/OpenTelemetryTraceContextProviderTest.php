<?php

declare(strict_types=1);

namespace Tests\Unit\Integration\OpenTelemetry\Trace;

use Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider;
use PHPUnit\Framework\TestCase;

final class OpenTelemetryTraceContextProviderTest extends TestCase
{
    public function testReturnsEmptyPayloadWhenSpanContextIsInvalid(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => ['trace_id' => '', 'span_id' => '']
        );

        self::assertSame([], $provider->provide());
    }

    public function testBuildsExpectedTraceFieldsFromSpanContext(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => true,
                'trace_flags' => 1,
            ]
        );

        self::assertSame(
            [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => '01',
                'traceparent' => '00-8b730fe79387fd9b697d5563c0712d87-04c52f40924f575e-01',
            ],
            $provider->provide()
        );
    }

    public function testKeepsTraceparentFromSpanContextWhenProvided(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'trace_flags' => 0,
                'traceparent' => '00-custom-traceparent',
            ]
        );

        self::assertSame(
            [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => '00',
                'traceparent' => '00-custom-traceparent',
            ],
            $provider->provide()
        );
    }

    public function testDerivesSampledFromTraceFlagsWhenSampledIsMissing(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'trace_flags' => 2,
            ]
        );

        self::assertSame(
            [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => '00',
                'traceparent' => '00-8b730fe79387fd9b697d5563c0712d87-04c52f40924f575e-02',
            ],
            $provider->provide()
        );
    }

    public function testReadsFromOpenTelemetryRuntimeWhenSpanContextIsValid(): void
    {
        if (!self::installFakeOpenTelemetryRuntime()) {
            self::markTestSkipped('Fake OpenTelemetry runtime cannot be installed in this environment.');
        }

        self::setFakeRuntimeCurrentSpan(
            true,
            '8b730fe79387fd9b697d5563c0712d87',
            '04c52f40924f575e',
            true,
            1
        );

        $provider = new OpenTelemetryTraceContextProvider();

        self::assertSame(
            [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => '01',
                'traceparent' => '00-8b730fe79387fd9b697d5563c0712d87-04c52f40924f575e-01',
            ],
            $provider->provide()
        );
    }

    public function testReturnsEmptyPayloadWhenOpenTelemetryRuntimeSpanContextIsInvalid(): void
    {
        if (!self::installFakeOpenTelemetryRuntime()) {
            self::markTestSkipped('Fake OpenTelemetry runtime cannot be installed in this environment.');
        }

        self::setFakeRuntimeCurrentSpan(false, '', '', false, 0);

        $provider = new OpenTelemetryTraceContextProvider();

        self::assertSame([], $provider->provide());
    }

    private static function installFakeOpenTelemetryRuntime(): bool
    {
        $spanExists = class_exists('OpenTelemetry\\API\\Trace\\Span');
        $contextExists = class_exists('OpenTelemetry\\Context\\Context');
        $spanContextExists = class_exists('OpenTelemetry\\API\\Trace\\SpanContext');

        if ($spanExists || $contextExists || $spanContextExists) {
            return $spanExists && $contextExists && $spanContextExists;
        }

        eval(<<<'PHP'
namespace OpenTelemetry\Context;
final class Context
{
    public static function getCurrent(): object
    {
        return new \stdClass();
    }
}

namespace OpenTelemetry\API\Trace;
final class SpanContext
{
    public function __construct(
        private bool $valid,
        private string $traceId,
        private string $spanId,
        private bool $sampled,
        private int $traceFlags
    ) {
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function getTraceFlags(): int
    {
        return $this->traceFlags;
    }
}

final class Span
{
    public static ?self $current = null;

    public function __construct(private SpanContext $context)
    {
    }

    public static function fromContext(object $context): self
    {
        return self::$current ?? new self(new SpanContext(false, '', '', false, 0));
    }

    public function getContext(): SpanContext
    {
        return $this->context;
    }
}
PHP);

        return true;
    }

    private static function setFakeRuntimeCurrentSpan(
        bool $valid,
        string $traceId,
        string $spanId,
        bool $sampled,
        int $traceFlags
    ): void {
        $spanClass = 'OpenTelemetry\\API\\Trace\\Span';
        $spanContextClass = 'OpenTelemetry\\API\\Trace\\SpanContext';

        if (!class_exists($spanClass) || !class_exists($spanContextClass)) {
            throw new \RuntimeException('Fake OpenTelemetry runtime is not available.');
        }

        /** @var object $spanContext */
        $spanContext = new $spanContextClass($valid, $traceId, $spanId, $sampled, $traceFlags);
        /** @var object $span */
        $span = new $spanClass($spanContext);

        $currentProperty = new \ReflectionProperty($spanClass, 'current');
        $currentProperty->setValue(null, $span);
    }
}
