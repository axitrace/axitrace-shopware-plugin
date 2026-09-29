<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Config;

use AxitraceShopware6\Config\PinterestCatalogIdMode;
use PHPUnit\Framework\TestCase;

final class PinterestCatalogIdModeTest extends TestCase
{
    public function testInvalidAndEmptyValuesKeepLegacyBehaviour(): void
    {
        self::assertSame(PinterestCatalogIdMode::Legacy, PinterestCatalogIdMode::fromConfigValue(null));
        self::assertSame(PinterestCatalogIdMode::Legacy, PinterestCatalogIdMode::fromConfigValue('unexpected'));
    }

    public function testConfiguredValuesResolve(): void
    {
        self::assertSame(PinterestCatalogIdMode::ProductNumber, PinterestCatalogIdMode::fromConfigValue('product_number'));
        self::assertSame(PinterestCatalogIdMode::ProductNumberLowercase, PinterestCatalogIdMode::fromConfigValue('product_number_lowercase'));
    }
}
