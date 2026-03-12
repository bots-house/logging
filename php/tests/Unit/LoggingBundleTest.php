<?php

declare(strict_types=1);

namespace Tests\Unit;

use Adheart\Logging\DependencyInjection\AdheartLoggingExtension;
use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingFormatterPass;
use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingProcessorsPass;
use Adheart\Logging\DependencyInjection\Compiler\RegisterLoggerServicesPass;
use Adheart\Logging\LoggingBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class LoggingBundleTest extends TestCase
{
    public function testReturnsAdheartLoggingExtensionInstance(): void
    {
        $bundle = new LoggingBundle();

        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(AdheartLoggingExtension::class, $extension);
    }

    public function testReturnsSameExtensionInstanceOnSubsequentCalls(): void
    {
        $bundle = new LoggingBundle();

        $first = $bundle->getContainerExtension();
        $second = $bundle->getContainerExtension();

        self::assertSame($first, $second);
    }

    public function testRegistersExpectedCompilerPassesOnBuild(): void
    {
        $bundle = new LoggingBundle();
        $container = new ContainerBuilder();

        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getPasses();
        self::assertContainsOnlyInstancesOf(CompilerPassInterface::class, $passes);
        self::assertTrue($this->containsPass($passes, RegisterLoggerServicesPass::class));
        self::assertTrue($this->containsPass($passes, ApplyLoggingProcessorsPass::class));
        self::assertTrue($this->containsPass($passes, ApplyLoggingFormatterPass::class));
    }

    /**
     * @param array<int, object> $passes
     */
    private function containsPass(array $passes, string $class): bool
    {
        foreach ($passes as $pass) {
            if ($pass instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
