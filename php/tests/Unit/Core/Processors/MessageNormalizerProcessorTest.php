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
}
