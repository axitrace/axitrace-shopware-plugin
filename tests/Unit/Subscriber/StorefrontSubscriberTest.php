<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Subscriber;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Exclusion\CustomerGroupExclusion;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use AxitraceShopware6\Subscriber\StorefrontSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageCriteriaEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StorefrontSubscriberTest extends TestCase
{
    public function testPageIsReadFromOfficialEventParameters(): void
    {
        [$subscriber, $context] = $this->subscriber();
        $product = new SalesChannelProductEntity();
        $product->setId('0191d3d2c1ce7a2ba9d1f2f2c8b1a001');
        $product->setProductNumber('Variant-A');
        $product->setTranslated(['name' => 'Variant A']);
        $product->setCalculatedPrice($this->price(12.5));
        $manufacturer = new ProductManufacturerEntity();
        $manufacturer->setId('0191d3d2c1ce7a2ba9d1f2f2c8b1a002');
        $manufacturer->setName('Raw brand');
        $manufacturer->setTranslated(['name' => 'Translated brand']);
        $product->setManufacturer($manufacturer);
        $category = new CategoryEntity();
        $category->setId('0191d3d2c1ce7a2ba9d1f2f2c8b1a003');
        $category->setName('Raw category');
        $category->setTranslated(['name' => 'Translated category']);
        $product->setCategories(new CategoryCollection([$category]));
        $page = new ProductPage();
        $page->setProduct($product);
        $event = new StorefrontRenderEvent('view', ['page' => $page], Request::create('/detail/x'), $context);

        $subscriber->onStorefrontRender($event);

        self::assertSame('Variant-A', $event->getParameters()['axitraceProductContext']['sku']);
        self::assertSame('variant-a', $event->getParameters()['axitraceProductContext']['pinterest_id']);
        self::assertSame('Translated brand', $event->getParameters()['axitraceProductContext']['brand']);
        self::assertSame('Translated category', $event->getParameters()['axitraceProductContext']['category']);
        self::assertSame('/en/axitrace/storefront-context', $event->getParameters()['axitraceConfig']['storefrontContextUrl']);
    }

    public function testTrackingStatusUrlIsRenderedOnlyWithExcludedCustomerGroups(): void
    {
        [$subscriber, $context] = $this->subscriber();
        $event = new StorefrontRenderEvent('view', [], Request::create('/'), $context);
        $subscriber->onStorefrontRender($event);
        self::assertArrayNotHasKey('trackingStatusUrl', $event->getParameters()['axitraceConfig'], 'without the setting the page is unchanged');

        [$subscriber, $context] = $this->subscriber(excludedGroups: ['0191d3d2c1ce7a2ba9d1f2f2c8b1ab2b']);
        $event = new StorefrontRenderEvent('view', [], Request::create('/'), $context);
        $subscriber->onStorefrontRender($event);
        $config = $event->getParameters()['axitraceConfig'];
        self::assertSame('/en/axitrace/tracking-status', $config['trackingStatusUrl']);
        self::assertStringNotContainsString('0191d3d2c1ce7a2ba9d1f2f2c8b1ab2b', (string) json_encode($config), 'the page is shared by the HTTP cache: no group and no visitor decision in it');
    }

    public function testBootstrapAsksTheTrackingStatusBeforeTheSdkLoads(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../src/Resources/views/storefront/layout/meta.html.twig');
        self::assertIsString($template);

        self::assertStringContainsString("{% if axitraceConfig.consent.mode == 'off' and axitraceConfig.trackingStatusUrl is not defined %}", $template, 'no static SDK tag when the visitor must be checked first');
        $loadAndInit = substr($template, (int) strpos($template, 'function loadAndInit()'), (int) strpos($template, 'function injectAndInit()') - (int) strpos($template, 'function loadAndInit()'));
        self::assertStringContainsString('window.fetch(config.trackingStatusUrl', $loadAndInit);
        self::assertStringContainsString('status.track === false', $loadAndInit);
        self::assertStringNotContainsString('createElement', $loadAndInit, 'the SDK tag is injected only after the answer');
    }

    public function testProductPageCriteriaLoadsCategoriesForInitialPageVisit(): void
    {
        [$subscriber, $context] = $this->subscriber();
        $criteria = new Criteria();
        $event = new ProductPageCriteriaEvent('0191d3d2c1ce7a2ba9d1f2f2c8b1a001', $criteria, $context);

        $subscriber->onProductPageCriteria($event);

        self::assertTrue($criteria->hasAssociation('categories'));
    }

    public function testProductPageCriteriaRemainsUntouchedWhenPluginIsDisabled(): void
    {
        [$subscriber, $context] = $this->subscriber(null, false);
        $criteria = new Criteria();
        $event = new ProductPageCriteriaEvent('0191d3d2c1ce7a2ba9d1f2f2c8b1a001', $criteria, $context);

        $subscriber->onProductPageCriteria($event);

        self::assertFalse($criteria->hasAssociation('categories'));
    }

    public function testCheckoutContextContainsPricedVariantItemsAndQuantity(): void
    {
        [$subscriber, $context] = $this->subscriber();
        $cart = new Cart('token');
        $cart->setPrice(new CartPrice(25, 30, 30, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));
        $item = new LineItem('line-1', LineItem::PRODUCT_LINE_ITEM_TYPE, '0191d3d2c1ce7a2ba9d1f2f2c8b1a009', 2);
        $item->setLabel('Variant A');
        $item->setPayload(['productNumber' => 'Variant-A']);
        $item->setPrice($this->price(15, 2));
        $cart->add($item);
        $page = new CheckoutConfirmPage();
        $page->setCart($cart);
        $request = Request::create('/checkout/confirm');
        $request->attributes->set('_route', 'frontend.checkout.confirm.page');
        $event = new StorefrontRenderEvent('view', ['page' => $page], $request, $context);

        $subscriber->onStorefrontRender($event);

        $checkout = $event->getParameters()['axitraceCheckoutContext'];
        self::assertSame(2, $checkout['quantity']);
        self::assertSame(30.0, $checkout['value']);
        self::assertSame('Variant-A', $checkout['items'][0]['item_id']);
        self::assertSame('variant-a', $checkout['items'][0]['pinterest_id']);
        self::assertSame(15.0, $checkout['items'][0]['price']);
    }

    public function testMissingPageDoesNotCreateContext(): void
    {
        [$subscriber, $context] = $this->subscriber();
        $event = new StorefrontRenderEvent('view', [], Request::create('/'), $context);
        $subscriber->onStorefrontRender($event);

        self::assertArrayNotHasKey('axitraceProductContext', $event->getParameters());
        self::assertArrayNotHasKey('axitraceCheckoutContext', $event->getParameters());
    }

    public function testExtractionFailureIsLoggedAndExposedToTemplate(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(self::stringContains('product context initialization failed'));
        [$subscriber, $context] = $this->subscriber($logger);
        $page = new ProductPage(); // deliberately no product: getProduct() throws an Error
        $request = Request::create('/detail/x');
        $request->attributes->set('_route', 'frontend.detail.page');
        $event = new StorefrontRenderEvent('view', ['page' => $page], $request, $context);

        $subscriber->onStorefrontRender($event);

        $error = $event->getParameters()['axitraceProductContextError'];
        self::assertMatchesRegularExpression('/^(?:Error|TypeError): .*ProductPage/', $error);

        $template = file_get_contents(__DIR__ . '/../../../src/Resources/views/storefront/layout/meta.html.twig');
        self::assertIsString($template);
        self::assertStringContainsString('AxiTrace product context could not be loaded.', $template);
        self::assertStringNotContainsString("' + {{ axitraceProductContextError", $template, 'Exception details must not be rendered to shoppers.');
    }

    /**
     * @param list<string> $excludedGroups
     *
     * @return array{StorefrontSubscriber, SalesChannelContext}
     */
    private function subscriber(?LoggerInterface $logger = null, bool $enabled = true, array $excludedGroups = []): array
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'AxitraceShopware6.config.enabled' => $enabled,
            'AxitraceShopware6.config.excludedCustomerGroups' => $excludedGroups,
            'AxitraceShopware6.config.publicKey' => 'pk_test_abcdef1234567890abcdef1234567890',
            'AxitraceShopware6.config.pinterestCatalogIdMode' => 'product_number_lowercase',
            default => null,
        });
        $logger ??= $this->createStub(LoggerInterface::class);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => match ($name) {
            'frontend.axitrace.tracking-status' => '/en/axitrace/tracking-status',
            default => '/en/axitrace/storefront-context',
        });
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel');
        $context->method('getCurrency')->willReturn($currency);

        $config = new PluginConfig($configService, new AxitraceCrypto('secret'), $logger);

        return [new StorefrontSubscriber(
            $config,
            new PinterestCatalogIdResolver(),
            new CustomerGroupExclusion($config),
            $router,
            $logger,
        ), $context];
    }

    private function price(float $unit, int $quantity = 1): CalculatedPrice
    {
        return new CalculatedPrice($unit, $unit * $quantity, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity);
    }
}
