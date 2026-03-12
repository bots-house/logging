<?php

declare(strict_types=1);

namespace Tests\Unit\DependencyInjection\Compiler;

use Adheart\Logging\DependencyInjection\Compiler\RegisterLoggerServicesPass;
use Adheart\Logging\Inventory\LoggerCatalogProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class RegisterLoggerServicesPassTest extends TestCase
{
    public function testDoesNothingWhenLoggerCatalogProviderServiceIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger.billing', new Definition(\stdClass::class));

        (new RegisterLoggerServicesPass())->process($container);

        self::assertFalse($container->hasDefinition(LoggerCatalogProvider::class));
    }

    public function testRegistersLoggerAndMonologLoggerServicesIntoCatalogProviderArgument(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LoggerCatalogProvider::class, new Definition(LoggerCatalogProvider::class));
        $container->setDefinition('logger', new Definition(\stdClass::class));
        $container->setDefinition('monolog.logger', new Definition(\stdClass::class));
        $container->setDefinition('monolog.logger.billing', new Definition(\stdClass::class));
        $container->setDefinition('monolog.logger.audit', new Definition(\stdClass::class));
        $container->setDefinition('app.not_logger', new Definition(\stdClass::class));

        (new RegisterLoggerServicesPass())->process($container);

        /** @var array<string, Reference> $references */
        $references = $container->getDefinition(LoggerCatalogProvider::class)->getArgument('$loggerServices');

        self::assertSame(
            [
                'logger',
                'monolog.logger',
                'monolog.logger.audit',
                'monolog.logger.billing',
            ],
            array_keys($references)
        );
        self::assertSame(
            [
                'logger' => 'logger',
                'monolog.logger' => 'monolog.logger',
                'monolog.logger.audit' => 'monolog.logger.audit',
                'monolog.logger.billing' => 'monolog.logger.billing',
            ],
            array_map(static fn (Reference $reference): string => (string) $reference, $references)
        );
    }

    public function testIncludesLoggerAliasesAndIgnoresNonLoggerAliases(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LoggerCatalogProvider::class, new Definition(LoggerCatalogProvider::class));
        $container->setDefinition('logger', new Definition(\stdClass::class));
        $container->setDefinition('monolog.logger', new Definition(\stdClass::class));
        $container->setAlias('monolog.logger.payments', new Alias('monolog.logger'));
        $container->setAlias('app.alias', new Alias('monolog.logger'));

        (new RegisterLoggerServicesPass())->process($container);

        /** @var array<string, Reference> $references */
        $references = $container->getDefinition(LoggerCatalogProvider::class)->getArgument('$loggerServices');

        self::assertArrayHasKey('logger', $references);
        self::assertArrayHasKey('monolog.logger', $references);
        self::assertArrayHasKey('monolog.logger.payments', $references);
        self::assertArrayNotHasKey('app.alias', $references);
        self::assertSame('monolog.logger.payments', (string) $references['monolog.logger.payments']);
    }

    public function testSetsEmptyLoggerServicesWhenNoCandidatesAreAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LoggerCatalogProvider::class, new Definition(LoggerCatalogProvider::class));
        $container->setDefinition('app.service', new Definition(\stdClass::class));

        (new RegisterLoggerServicesPass())->process($container);

        self::assertSame(
            [],
            $container->getDefinition(LoggerCatalogProvider::class)->getArgument('$loggerServices')
        );
    }
}
