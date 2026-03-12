<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Extractor;

use Adheart\Logging\Inventory\Extractor\LogUsageExtractor;
use Adheart\Logging\Inventory\Model\LogUsage;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class UsageCollectingVisitorTest extends TestCase
{
    public function testCollectsUsageFromContainerGetCall(): void
    {
        $code = <<<'PHP'
        <?php
        $this->container->get('monolog.logger.billing')->info('Order shipped');
        PHP;

        $usages = $this->parseUsages($code);

        self::assertCount(1, $usages);
        $usage = $usages[0];

        self::assertSame('Order shipped', $usage->message);
        self::assertSame('info', $usage->severity);
        self::assertSame('billing', $usage->loggerName);
        self::assertSame('billing', $usage->channel);
        self::assertSame('Billing', $usage->domain);
        self::assertSame([], $usage->contextKeys);
        self::assertSame('', $usage->context);
    }

    public function testGuessesLoggerFromVariableNameWhenKnown(): void
    {
        $code = <<<'PHP'
        <?php
        $billingLogger->warning('Budget');
        PHP;

        $usages = $this->parseUsages($code, ['billing']);

        self::assertCount(1, $usages);
        $usage = $usages[0];

        self::assertSame('warning', $usage->severity);
        self::assertSame('billing', $usage->loggerName);
        self::assertSame('billing', $usage->channel);
    }

    public function testLogMethodUsesClassConstSeverityAndDefaultChannel(): void
    {
        $code = <<<'PHP'
        <?php
        use Psr\Log\LogLevel;

        Log::log(LogLevel::ERROR, 'boom', ['foo' => 'bar']);
        PHP;

        $usages = $this->parseUsages($code, [], 'fallback');

        self::assertCount(1, $usages);
        $usage = $usages[0];

        self::assertSame('boom', $usage->message);
        self::assertSame('literal', $usage->messageType);
        self::assertSame('Psr\Log\LogLevel::ERROR', $usage->severity);
        self::assertSame('unknown', $usage->loggerName);
        self::assertSame('fallback', $usage->channel);
        self::assertSame(['foo'], $usage->contextKeys);
        self::assertSame("['foo' => 'bar']", $usage->context);
    }

    /**
     * @param string $code
     * @param string[] $knownLoggerNames
     */
    private function parseUsages(
        string $code,
        array $knownLoggerNames = ['billing'],
        string $defaultChannel = 'billing'
    ): array {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse($code);

        $extractor = new LogUsageExtractor();

        /** @var LogUsage[] $usages */
        $usages = $extractor->extractFromAst(
            $stmts ?? [],
            'src/Billing/Invoice.php',
            $knownLoggerNames,
            $defaultChannel
        );

        return $usages;
    }
}
