<?php

declare(strict_types=1);

namespace Tests\Functional;

use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use Adheart\Logging\DependencyInjection\AdheartLoggingExtension;
use Adheart\Logging\LoggingBundle;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

final class LoggingBundleSmokeTest extends TestCase
{
    public function testBundleBootCompilesContainerAndProcessesLogRecord(): void
    {
        $container = new ContainerBuilder();

        $container->setDefinition('monolog.handler.test', (new Definition(TestHandler::class))->setPublic(true));

        $loggerDefinition = (new Definition(Logger::class))
            ->setArgument(0, 'app')
            ->addMethodCall('pushHandler', [new Reference('monolog.handler.test')])
            ->setPublic(true);
        $container->setDefinition('monolog.logger', $loggerDefinition);
        $container->setAlias('logger', new Alias('monolog.logger', true));

        $bundle = new LoggingBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);
        self::assertInstanceOf(AdheartLoggingExtension::class, $extension);

        $extension->load([
            [
                'processors' => ['message_normalizer'],
                'formatter' => [
                    'schema_version' => '1.0.0',
                    'service_name' => 'smoke-app',
                    'service_version' => 'test',
                ],
            ],
        ], $container);

        $container->compile();

        /** @var Logger $logger */
        $logger = $container->get('logger');
        self::assertInstanceOf(Logger::class, $logger);

        /** @var TestHandler $handler */
        $handler = $container->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertInstanceOf(SchemaFormatterV1::class, $handler->getFormatter());

        $logger->info('{"event":"login","status":"ok"}', ['request_id' => 'r-1']);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        $record = $this->recordToArray($records[0]);
        self::assertSame('[json moved to context.message_json]', $record['message']);
        self::assertSame('{"event":"login","status":"ok"}', $record['context']['message_json']);
        self::assertSame('r-1', $record['context']['request_id']);

        $formatted = $handler->getFormatter()->format($records[0]);
        $payload = json_decode($formatted, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('smoke-app', $payload['service']['name']);
        self::assertSame('1.0.0', $payload['version']);
    }

    /**
     * @param mixed $record
     *
     * @return array<string,mixed>
     */
    private function recordToArray(mixed $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        if (is_object($record) && method_exists($record, 'toArray')) {
            return $record->toArray();
        }

        return [];
    }
}
