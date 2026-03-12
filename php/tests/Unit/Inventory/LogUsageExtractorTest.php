<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Adheart\Logging\Inventory\Extractor\LogUsageExtractor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class LogUsageExtractorTest extends TestCase
{
    public function testExtractsSeverityMessageAndLoggerFromDifferentPatterns(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class Sample
{
    #[Autowire(service: 'monolog.logger.payments')]
    private LoggerInterface $paymentsLogger;

    public function __construct(LoggerInterface $billingLogger)
    {
        $this->billingLogger = $billingLogger;
    }

    public function run(LoggerInterface $logger, $container): void
    {
        $logger->info('payment completed', ['trace_id' => 'x']);
        $logger->warning('pay' . 'ment');
        $logger->error('API exception: ' . $this->resolveError(), ['exception' => new \RuntimeException()]);
        $container->get('monolog.logger.audit')->error(sprintf('oops %s', 'x'));
        $this->paymentsLogger->log(\Psr\Log\LogLevel::CRITICAL, self::MESSAGE_TEMPLATE);
        $this->billingLogger->debug('billing');
    }

    private function resolveError(): string
    {
        return 'x';
    }

    private const MESSAGE_TEMPLATE = 'template';
}
PHP;

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);

        self::assertNotNull($stmts);

        $extractor = new LogUsageExtractor();
        $usages = $extractor->extractFromAst($stmts, 'src/Billing/Sample.php', ['payments', 'billing', 'audit'], 'app');

        self::assertCount(6, $usages);

        self::assertSame('payment completed', $usages[0]->message);
        self::assertSame('literal', $usages[0]->messageType);
        self::assertSame('info', $usages[0]->severity);
        self::assertSame('unknown', $usages[0]->loggerName);
        self::assertSame('billing', $usages[0]->channel);
        self::assertSame(['trace_id'], $usages[0]->contextKeys);

        self::assertSame('payment', $usages[1]->message);
        self::assertSame('concat', $usages[1]->messageType);

        self::assertSame('\'API exception: \' . $this->resolveError()', $usages[2]->message);
        self::assertSame('dynamic', $usages[2]->messageType);
        self::assertSame(['exception'], $usages[2]->contextKeys);

        self::assertSame('oops %s', $usages[3]->message);
        self::assertSame('sprintf', $usages[3]->messageType);
        self::assertSame('audit', $usages[3]->loggerName);
        self::assertSame('audit', $usages[3]->channel);

        self::assertSame('App\\Sample::MESSAGE_TEMPLATE', $usages[4]->message);
        self::assertSame('const', $usages[4]->messageType);
        self::assertSame('Psr\\Log\\LogLevel::CRITICAL', $usages[4]->severity);
        self::assertSame('payments', $usages[4]->loggerName);

        self::assertSame('billing', $usages[5]->loggerName);
        self::assertSame('billing', $usages[5]->channel);
        self::assertSame('Billing', $usages[5]->domain);
    }
}
