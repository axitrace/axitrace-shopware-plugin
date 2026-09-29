<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Storefront;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use AxitraceShopware6\Storefront\StorefrontContextProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class StorefrontContextProviderTest extends TestCase
{
    public function testStateNormalizationStripsOnlyIsoCountryPrefix(): void
    {
        $method = new \ReflectionMethod(StorefrontContextProvider::class, 'normalizeState');
        $provider = (new \ReflectionClass(StorefrontContextProvider::class))->newInstanceWithoutConstructor();

        self::assertSame('BE', $method->invoke($provider, 'CH-BE'));
        self::assertSame('BE', $method->invoke($provider, 'BE'));
    }

    public function testCurrentCartUsesAuthoritativeVariantMetadata(): void
    {
        $id = '0191d3d2c1ce7a2ba9d1f2f2c8b1a009';
        $cart = new Cart('token');
        $cart->setPrice(new CartPrice(25, 30, 30, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));
        $lineItem = new LineItem('line-1', LineItem::PRODUCT_LINE_ITEM_TYPE, $id, 2);
        $lineItem->setLabel('stale label');
        $lineItem->setPayload(['productNumber' => 'stale-sku']);
        $lineItem->setPrice($this->price(15, 2));
        $cart->add($lineItem);

        $product = new SalesChannelProductEntity();
        $product->setId($id);
        $product->setProductNumber('Actual-Variant');
        $product->setTranslated(['name' => 'Actual Variant']);
        $product->setCalculatedPrice($this->price(15));
        $products = new SalesChannelProductCollection([$product]);
        $repo = $this->createMock(SalesChannelRepository::class);
        $repo->method('search')->willReturnCallback(static function ($criteria, $context) use ($products): EntitySearchResult {
            return new EntitySearchResult('product', 1, $products, null, $criteria, Context::createDefaultContext());
        });
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($cart);
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => str_ends_with($key, 'pinterestCatalogIdMode') ? 'product_number_lowercase' : null);
        $logger = $this->createStub(LoggerInterface::class);
        $provider = new StorefrontContextProvider(
            $cartService,
            $repo,
            new PluginConfig($configService, new AxitraceCrypto('secret'), $logger),
            new PinterestCatalogIdResolver(),
        );
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn('token');
        $context->method('getSalesChannelId')->willReturn('sales-channel');
        $context->method('getCurrency')->willReturn($currency);

        $result = $provider->load($context, $id, false);

        self::assertSame(2, $result['cart']['quantity']);
        self::assertSame(30.0, $result['cart']['value']);
        self::assertSame('Actual-Variant', $result['cart']['items'][0]['sku']);
        self::assertSame('actual-variant', $result['cart']['items'][0]['pinterest_id']);
        self::assertSame('Actual Variant', $result['product']['name']);
        self::assertArrayNotHasKey('customer', $result);
    }

    private function price(float $unit, int $quantity = 1): CalculatedPrice
    {
        return new CalculatedPrice($unit, $unit * $quantity, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity);
    }
}
