<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Adheart\Logging\Inventory\LoggerCatalogProvider;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerCatalogProviderTest extends TestCase
{
    public function testBuildCatalogUsesLoggerInstanceNames(): void
    {
        $provider = new LoggerCatalogProvider(
            [
                'monolog.logger.shipping' => new Logger('shipping-service'),
                'service.custom' => new \stdClass(),
            ]
        );

        $catalog = $provider->buildCatalog();

        self::assertSame(
            [
                'logger' => 'app',
                'monolog.logger.shipping' => 'shipping-service',
                'service.custom' => 'service.custom',
            ],
            $catalog->serviceToLogger
        );
    }

    public function testBuildCatalogAddsAppLoggerWhenMissing(): void
    {
        $provider = new LoggerCatalogProvider([]);

        $catalog = $provider->buildCatalog();

        self::assertSame(['logger' => 'app'], $catalog->serviceToLogger);
    }
}
