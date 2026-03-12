<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Model;

use Adheart\Logging\Inventory\Model\LoggerCatalog;
use PHPUnit\Framework\TestCase;

final class LoggerCatalogTest extends TestCase
{
    public function testLoggerToServiceReturnsSortedMapWithFirstService(): void
    {
        $catalog = new LoggerCatalog(
            [
                'service.third' => 'logger.c',
                'service.first' => 'logger.a',
                'service.zero' => 'logger.b',
                'service.second' => 'logger.c',
            ]
        );

        $result = $catalog->loggerToService();

        $expected = [
            'logger.a' => 'service.first',
            'logger.b' => 'service.zero',
            'logger.c' => 'service.third',
        ];

        self::assertSame($expected, $result);
    }

    public function testKnownLoggerNamesKeepsUniqueOrder(): void
    {
        $catalog = new LoggerCatalog(
            [
                'app.alpha' => 'logger.alpha',
                'app.beta' => 'logger.beta',
                'app.duplicate' => 'logger.alpha',
                'app.gamma' => 'logger.gamma',
            ]
        );

        $names = $catalog->knownLoggerNames();

        self::assertSame(['logger.alpha', 'logger.beta', 'logger.gamma'], $names);
    }

    public function testLoggerNameByServiceIdReturnsLoggerName(): void
    {
        $catalog = new LoggerCatalog(['inventory.service' => 'inventory.logger']);

        $name = $catalog->loggerNameByServiceId('inventory.service');

        self::assertSame('inventory.logger', $name);
    }

    public function testLoggerNameByServiceIdReturnsNullWhenUnknown(): void
    {
        $catalog = new LoggerCatalog(['some.service' => 'some.logger']);

        $name = $catalog->loggerNameByServiceId('missing.service');

        self::assertNull($name);
    }
}
