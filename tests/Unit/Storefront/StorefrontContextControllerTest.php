<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Storefront;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use AxitraceShopware6\Storefront\StorefrontContextController;
use AxitraceShopware6\Storefront\StorefrontContextProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\XmlFileLoader;

final class StorefrontContextControllerTest extends TestCase
{
    public function testOfficialStorefrontRouteContractIsDeclared(): void
    {
        $classRoute = (new \ReflectionClass(StorefrontContextController::class))->getAttributes(Route::class)[0]->newInstance();
        $methodRoute = (new \ReflectionMethod(StorefrontContextController::class, 'context'))->getAttributes(Route::class)[0]->newInstance();

        self::assertSame(['storefront'], $classRoute->getDefaults()[\Shopware\Core\PlatformRequest::ATTRIBUTE_ROUTE_SCOPE]);
        self::assertSame('/axitrace/storefront-context', $methodRoute->getPath());
        self::assertTrue($methodRoute->getDefaults()[\Shopware\Core\PlatformRequest::ATTRIBUTE_NO_STORE]);
    }

    public function testPluginRoutesFileLoadsTheControllerRoute(): void
    {
        $configDir = realpath(__DIR__ . '/../../../src/Resources/config');
        self::assertIsString($configDir);
        $locator = new FileLocator([$configDir]);
        $resolver = new LoaderResolver([
            new XmlFileLoader($locator),
            new AttributeFileLoader($locator, new AttributeRouteControllerLoader()),
        ]);
        $routes = (new DelegatingLoader($resolver))->load('routes.xml');

        self::assertNotNull($routes->get('frontend.axitrace.storefront-context'));
        self::assertSame('/axitrace/storefront-context', $routes->get('frontend.axitrace.storefront-context')?->getPath());
    }

    public function testResponseIsPrivateNoStoreAndCustomerRequiresHeaderPlusCookie(): void
    {
        [$controller, $context] = $this->controller(true);
        $request = Request::create('https://shop.example/axitrace/storefront-context');
        $request->headers->set('Origin', 'https://shop.example');
        $request->cookies->set(ConsentGate::DEFAULT_CONSENT_COOKIE, '1');

        $withoutHeader = $controller->context($request, $context);
        self::assertArrayNotHasKey('customer', $this->json($withoutHeader));
        self::assertStringContainsString('no-store', (string) $withoutHeader->headers->get('Cache-Control'));

        $request->headers->set('X-AxiTrace-Consent', 'granted');
        $granted = $controller->context($request, $context);
        self::assertSame('guest@example.com', $this->json($granted)['customer']['email']);
        self::assertSame('Ada', $this->json($granted)['customer']['firstName']);
        self::assertSame('BE', $this->json($granted)['customer']['state']);
    }

    public function testHeaderWithoutConsentCookieNeverReturnsCustomer(): void
    {
        [$controller, $context] = $this->controller(true);
        $request = Request::create('https://shop.example/axitrace/storefront-context');
        $request->headers->set('Origin', 'https://shop.example');
        $request->headers->set('X-AxiTrace-Consent', 'granted');

        self::assertArrayNotHasKey('customer', $this->json($controller->context($request, $context)));
    }

    public function testDisabledPluginAndCrossOriginRequestsAreRejected(): void
    {
        [$disabled, $context] = $this->controller(false);
        self::assertSame(404, $disabled->context(Request::create('https://shop.example/axitrace/storefront-context'), $context)->getStatusCode());

        [$enabled, $context] = $this->controller(true);
        $request = Request::create('https://shop.example/axitrace/storefront-context');
        $request->headers->set('Origin', 'https://attacker.example');
        self::assertSame(403, $enabled->context($request, $context)->getStatusCode());
    }

    public function testInvalidProductIdIsRejectedBeforeProviderLookup(): void
    {
        [$controller, $context] = $this->controller(true);
        $request = Request::create('https://shop.example/axitrace/storefront-context?productId=not-a-uuid');
        self::assertSame(400, $controller->context($request, $context)->getStatusCode());
    }

    public function testConsentOffAllowsCustomerWithoutCookieButCannotOverridePluginGate(): void
    {
        foreach (['off' => true, 'browser' => false, 'all' => false] as $mode => $expected) {
            [$controller, $context] = $this->controller(true, $mode);
            foreach ([null, 'denied'] as $cookie) {
                $request = Request::create('https://shop.example/axitrace/storefront-context');
                $request->headers->set('X-AxiTrace-Consent-Required', 'false');
                if ($cookie !== null) {
                    $request->cookies->set(ConsentGate::DEFAULT_CONSENT_COOKIE, $cookie);
                }
                $body = $this->json($controller->context($request, $context));
                self::assertSame($expected, isset($body['customer']), $mode);
            }
        }
    }

    /** @return array{StorefrontContextController, SalesChannelContext} */
    private function controller(bool $enabled, string $mode = "off"): array
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'AxitraceShopware6.config.enabled' => $enabled,
            'AxitraceShopware6.config.consentMode' => $mode,
            'AxitraceShopware6.config.publicKey' => $enabled ? 'pk_test_abcdef1234567890abcdef1234567890' : '',
            default => null,
        });
        $logger = $this->createStub(LoggerInterface::class);
        $config = new PluginConfig($configService, new AxitraceCrypto('secret'), $logger);
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn(new Cart('token'));
        $provider = new StorefrontContextProvider(
            $cartService,
            $this->createMock(SalesChannelRepository::class),
            $config,
            new PinterestCatalogIdResolver(),
        );
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $customer = new CustomerEntity();
        $customer->setEmail('guest@example.com');
        $customer->setFirstName('Ada');
        $customer->setLastName('Lovelace');
        $address = new CustomerAddressEntity();
        $address->setCity('Bern');
        $state = new CountryStateEntity();
        $state->setShortCode('CH-BE');
        $address->setCountryState($state);
        $customer->setActiveBillingAddress($address);
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel');
        $context->method('getToken')->willReturn('token');
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getCustomer')->willReturn($customer);

        return [new StorefrontContextController($provider, $config, new ConsentGate()), $context];
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
