<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Renderer;

use Adheart\Logging\Inventory\Model\LogUsage;
use Adheart\Logging\Inventory\Model\ScanResult;
use Adheart\Logging\Inventory\Renderer\LogUsageRenderer;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LogUsageRendererTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testRenderJsonIncludesSummaryAndUsagePayload(): void
    {
        $usages = [
            new LogUsage(
                'First message',
                'static',
                'info',
                'logger.main',
                'channel.one',
                ['key-one'],
                'context data',
                'src/one.php',
                10,
                'domain.primary'
            ),
            new LogUsage(
                'First message',
                'dynamic',
                'error',
                'logger.secondary',
                'channel.two',
                [],
                '',
                'src/two.php',
                20,
                'domain.secondary'
            ),
        ];

        $renderer = new LogUsageRenderer();
        $output = new BufferedOutput();
        $output->setDecorated(false);

        $renderer->renderJson(
            $output,
            $usages,
            true,
            new ScanResult($usages, ['src/bad.php' => 'failed to parse'], 7)
        );

        $payload = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(7, $payload['scannedFiles']);
        self::assertSame(['src/bad.php' => 'failed to parse'], $payload['parseErrors']);
        self::assertCount(2, $payload['usages']);
        self::assertSame('src/one.php:10', $payload['usages'][0]['location']);
        self::assertSame('First message', $payload['usages'][0]['message']);
        self::assertSame([
            'totalUsages' => 2,
            'uniqueMessages' => 1,
            'dynamicMessages' => 1,
        ], $payload['summary']);
    }

    /**
     * @throws JsonException
     */
    public function testRenderJsonSkipsSummaryWhenDisabled(): void
    {
        $usage = new LogUsage(
            'Another message',
            'static',
            'info',
            'logger',
            'channel',
            [],
            '',
            'src/file.php',
            3,
            'domain'
        );

        $renderer = new LogUsageRenderer();
        $output = new BufferedOutput();
        $output->setDecorated(false);

        $renderer->renderJson($output, [$usage], false, new ScanResult([$usage], [], 1));

        $payload = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('summary', $payload);
        self::assertSame(['parseErrors' => [], 'scannedFiles' => 1], [
            'parseErrors' => $payload['parseErrors'],
            'scannedFiles' => $payload['scannedFiles'],
        ]);
    }

    public function testRenderSummaryPrintsTotals(): void
    {
        $usages = [
            new LogUsage(
                'Static message',
                'static',
                'notice',
                'logger.one',
                'channel',
                [],
                '',
                'src/one.php',
                1,
                'domain'
            ),
            new LogUsage(
                'Dynamic message',
                'dynamic',
                'error',
                'logger.two',
                'channel',
                [],
                '',
                'src/two.php',
                2,
                'domain'
            ),
        ];

        $renderer = new LogUsageRenderer();
        $output = new BufferedOutput();
        $output->setDecorated(false);
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $renderer->renderSummary($io, $usages, 2);

        $rendered = $output->fetch();

        self::assertStringContainsString('Summary', $rendered);
        self::assertStringContainsString('Total usages: 2', $rendered);
        self::assertStringContainsString('Unique messages: 2', $rendered);
        self::assertStringContainsString('Dynamic messages: 1', $rendered);
        self::assertStringContainsString('Parse errors: 2', $rendered);
    }

    public function testRenderTableCompactTruncatesMessageAndContext(): void
    {
        $usage = new LogUsage(
            str_repeat('x', 130),
            'static',
            'warning',
            'logger',
            'channel',
            ['key1'],
            'longcontextmessage',
            'src/File.php',
            10,
            'domain'
        );

        $renderer = new LogUsageRenderer();
        $output = new BufferedOutput();
        $output->setDecorated(false);
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $renderer->renderTable($io, [$usage], true, 'compact', 5);

        $rendered = $output->fetch();

        self::assertStringContainsString('[1] ' . str_repeat('x', 120) . '...', $rendered);
        self::assertStringContainsString('[WARNING]', $rendered);
        self::assertStringContainsString('context.keys=[1] [1]=key1', $rendered);
        self::assertStringContainsString(
            'context=longc ... [truncated 13 chars, adjust with --context-max-chars]',
            $rendered
        );
    }
}
