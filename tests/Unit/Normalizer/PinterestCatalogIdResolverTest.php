<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Config\PinterestCatalogIdMode;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PinterestCatalogIdResolverTest extends TestCase
{
    #[DataProvider('ids')]
    public function testResolve(PinterestCatalogIdMode $mode, string $productNumber, string $uuid, ?string $expected): void
    {
        self::assertSame($expected, (new PinterestCatalogIdResolver())->resolve($mode, $productNumber, $uuid));
    }

    public static function ids(): iterable
    {
        yield 'legacy omits pinterest override' => [PinterestCatalogIdMode::Legacy, 'Variant-A', 'ABCDEF', null];
        yield 'product number preserves case' => [PinterestCatalogIdMode::ProductNumber, ' Variant-A ', 'ABCDEF', 'Variant-A'];
        yield 'lowercase mode normalizes product number' => [PinterestCatalogIdMode::ProductNumberLowercase, ' Variant-A ', 'ABCDEF', 'variant-a'];
        yield 'missing number falls back to normalized uuid' => [PinterestCatalogIdMode::ProductNumber, '', 'ABCDEF0123', 'abcdef0123'];
        yield 'legacy never exposes fallback' => [PinterestCatalogIdMode::Legacy, '', 'ABCDEF0123', null];
    }
}
