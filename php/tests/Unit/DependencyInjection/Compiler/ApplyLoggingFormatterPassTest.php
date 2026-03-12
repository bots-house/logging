<?php

declare(strict_types=1);

namespace Tests\Unit\DependencyInjection\Compiler;

use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingFormatterPass;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ApplyLoggingFormatterPassTest extends TestCase
{
    public function testAppliesFormatterToAllFormattableHandlers(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setDefinition('monolog.handler.console', new Definition(StreamHandler::class));
        $container->setDefinition('monolog.handler.no_formatter', new Definition(Logger::class));
        $container->setDefinition('app.logging.formatter', new Definition());

        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        $pass = new ApplyLoggingFormatterPass();
        $pass->process($container);

        $stdoutCalls = $container->getDefinition('monolog.handler.stdout')->getMethodCalls();
        self::assertCount(1, $stdoutCalls);
        self::assertSame('setFormatter', $stdoutCalls[0][0]);
        self::assertSame('app.logging.formatter', (string)$stdoutCalls[0][1][0]);

        $consoleCalls = $container->getDefinition('monolog.handler.console')->getMethodCalls();
        self::assertCount(1, $consoleCalls);
        self::assertSame('setFormatter', $consoleCalls[0][0]);

        $nonFormattableCalls = $container->getDefinition('monolog.handler.no_formatter')->getMethodCalls();
        self::assertSame([], $nonFormattableCalls);
    }

    public function testThrowsOnUnknownFormatterServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        $pass = new ApplyLoggingFormatterPass();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configured logging formatter "app.logging.formatter" is not a registered service.'
        );

        $pass->process($container);
    }

    public function testAcceptsFormatterServiceAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setDefinition('app.logging.formatter.actual', new Definition());
        $container->setAlias('app.logging.formatter.alias', new Alias('app.logging.formatter.actual'));
        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter.alias');

        (new ApplyLoggingFormatterPass())->process($container);

        $calls = $container->getDefinition('monolog.handler.stdout')->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('setFormatter', $calls[0][0]);
        self::assertSame('app.logging.formatter.alias', (string) $calls[0][1][0]);
    }

    public function testResolvesChildDefinitionClassThroughParent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.handler.parent', new Definition(StreamHandler::class));
        $container->setDefinition('monolog.handler.child', new ChildDefinition('app.handler.parent'));
        $container->setDefinition('app.logging.formatter', new Definition());
        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        (new ApplyLoggingFormatterPass())->process($container);

        $calls = $container->getDefinition('monolog.handler.child')->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('setFormatter', $calls[0][0]);
        self::assertSame('app.logging.formatter', (string) $calls[0][1][0]);
    }

    public function testSkipsWhenFormatterServiceIdIsEmptyString(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setParameter('logging.formatter_service_id', '');

        (new ApplyLoggingFormatterPass())->process($container);

        self::assertSame([], $container->getDefinition('monolog.handler.stdout')->getMethodCalls());
    }

    public function testSkipsWhenFormatterParameterIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));

        (new ApplyLoggingFormatterPass())->process($container);

        self::assertSame([], $container->getDefinition('monolog.handler.stdout')->getMethodCalls());
    }

    public function testSkipsWhenFormatterServiceIdIsNull(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setParameter('logging.formatter_service_id', null);

        (new ApplyLoggingFormatterPass())->process($container);

        self::assertSame([], $container->getDefinition('monolog.handler.stdout')->getMethodCalls());
    }

    public function testAppliesFormatterToHandlerAliasTargetDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.target', new Definition(StreamHandler::class));
        $container->setAlias('monolog.handler.app', new Alias('monolog.handler.target'));
        $container->setDefinition('app.logging.formatter', new Definition());
        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        (new ApplyLoggingFormatterPass())->process($container);

        $calls = $container->getDefinition('monolog.handler.target')->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('setFormatter', $calls[0][0]);
        self::assertSame('app.logging.formatter', (string) $calls[0][1][0]);
    }

    public function testAppliesFormatterWhenHandlerClassCannotBeResolved(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.dynamic', new Definition());
        $container->setDefinition('app.logging.formatter', new Definition());
        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        (new ApplyLoggingFormatterPass())->process($container);

        $calls = $container->getDefinition('monolog.handler.dynamic')->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('setFormatter', $calls[0][0]);
        self::assertSame('app.logging.formatter', (string) $calls[0][1][0]);
    }
}
