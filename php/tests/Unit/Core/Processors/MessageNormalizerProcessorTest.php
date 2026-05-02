<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Processors;

use Adheart\Logging\Core\Processors\MessageNormalizerProcessor;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerProcessorTest extends TestCase
{
    public function testKeepsPlainMessageUnchanged(): void
    {
        $processor = new MessageNormalizerProcessor();

        $result = $processor([
            'message' => 'plain message',
            'context' => ['foo' => 'bar'],
            'extra' => [],
        ]);

        self::assertSame('plain message', $result['message']);
        self::assertSame(['foo' => 'bar'], $result['context']);
        self::assertArrayNotHasKey('message_json', $result['context']);
    }

    public function testMovesJsonLikeMessageToContextAndSetsServiceMessage(): void
    {
        $processor = new MessageNormalizerProcessor();

        $result = $processor([
            'message' => '{"event":"login","status":"ok"}',
            'context' => ['foo' => 'bar'],
            'extra' => [],
        ]);

        self::assertSame('[json moved to context.message_json]', $result['message']);
        self::assertSame('{"event":"login","status":"ok"}', $result['context']['message_json']);
        self::assertSame('bar', $result['context']['foo']);
    }

    public function testNormalizesNonStringMessageAndInvalidContext(): void
    {
        $processor = new MessageNormalizerProcessor();

        $result = $processor([
            'message' => 123,
            'context' => 'not-an-array',
            'extra' => [],
        ]);

        self::assertSame('123', $result['message']);
        self::assertSame([], $result['context']);
        self::assertArrayNotHasKey('message_json', $result['context']);
    }

    public function testSupportsMonolog3StyleRecordObject(): void
    {
        $processor = new MessageNormalizerProcessor();

        $record = new class ('{"event":"login"}', ['foo' => 'bar']) {
            public function __construct(
                public string $message,
                public array $context,
            ) {
            }

            public function with(?string $message = null, ?array $context = null): self
            {
                return new self(
                    $message ?? $this->message,
                    $context ?? $this->context,
                );
            }
        };

        $result = $processor($record);

        self::assertNotSame($record, $result);
        self::assertSame('[json moved to context.message_json]', $result->message);
        self::assertSame('{"event":"login"}', $result->context['message_json']);
        self::assertSame('bar', $result->context['foo']);
    }

    public function testReturnsObjectRecordUnchangedWhenWithMethodIsMissing(): void
    {
        $processor = new MessageNormalizerProcessor();

        $record = new class {
            public string $message = '{"event":"login"}';
            /** @var array<string,mixed> */
            public array $context = [];
        };

        $result = $processor($record);

        self::assertSame($record, $result);
        self::assertSame('{"event":"login"}', $result->message);
        self::assertArrayNotHasKey('message_json', $result->context);
    }

    public function testCastsNonStringMessageOnObjectRecord(): void
    {
        $processor = new MessageNormalizerProcessor();

        $record = new class (123, ['foo' => 'bar']) {
            /**
             * @param array<string,mixed> $context
             */
            public function __construct(
                public mixed $message,
                public array $context,
            ) {
            }

            /**
             * @param array<string,mixed>|null $context
             */
            public function with(?string $message = null, ?array $context = null): self
            {
                return new self(
                    $message ?? $this->message,
                    $context ?? $this->context,
                );
            }
        };

        $result = $processor($record);

        self::assertSame('123', $result->message);
        self::assertSame('bar', $result->context['foo']);
        self::assertArrayNotHasKey('message_json', $result->context);
    }
}
