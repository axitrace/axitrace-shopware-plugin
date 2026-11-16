<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Storefront;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\Exclusion\CustomerGroupExclusion;
use AxitraceShopware6\Normalizer\ConversionValueBasis;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use AxitraceShopware6\Order\PaidTransactionResolver;
use AxitraceShopware6\Storefront\PurchaseContextProvider;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use AxitraceShopware6\Tests\Unit\Normalizer\OrderFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class PurchaseContextProviderTest extends TestCase
{
    private const SALES_CHANNEL = 'sales-channel-001';
    private const B2B_GROUP = '0192a0b1c2d3e4f5a6b7c8d9e0f1b2b2';

    public function testPaidOrderGetsTheEventIdOfTheServerSidePurchase(): void
    {
        $order = $this->paidOrder();

        $context = $this->provider($order)->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext());

        self::assertNotNull($context);
        // Exactly the id OrderPaidSubscriber sends: UUID v5 of order + the paid transaction.
        self::assertSame(
            (new UuidV5Generator())->forOrder(OrderFixtures::ORDER_ID, OrderFixtures::TRANSACTION_ID),
            $context['eventId'],
        );
        self::assertSame('10042', $context['orderNumber']);
        self::assertSame('EUR', $context['currency']);
        self::assertSame(2, $context['quantity']);
        self::assertSame([[
            'productId' => OrderFixtures::PRODUCT_ID,
            'sku' => 'SW-1001',
            'name' => 'Desk chair',
            'quantity' => 2,
            'price' => 59.5,
            'currency' => 'EUR',
            'pinterest_id' => 'sw-1001',
        ]], $context['items']);
    }

    public function testValueIsTheServerSideConversionValueUnderTheMerchantsBasis(): void
    {
        $order = $this->paidOrder();
        $basis = ConversionValueBasis::NetTotal;

        $context = $this->provider($order, ['conversionValueBasis' => $basis->value])
            ->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext());

        self::assertNotNull($context);
        $serverValue = (new OrderEventNormalizer())->normalize($order, 'x', 'pk', $basis)['data']['value']['amount'];
        self::assertSame(round($serverValue, 2), $context['value']);
        self::assertSame(100.0, $context['value']);
    }

    public function testOrderAwaitingPaymentGetsNoContext(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('open')]);

        self::assertNull($this->provider($order)->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext()));
    }

    public function testExcludedCustomerGroupGetsNoContext(): void
    {
        $order = $this->paidOrder();
        $order->setCustomFields([CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP => self::B2B_GROUP]);

        $provider = $this->provider($order, ['excludedCustomerGroups' => [self::B2B_GROUP]]);

        self::assertNull($provider->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext()));
    }

    public function testServerSideConsentRefusalGetsNoContext(): void
    {
        $order = $this->paidOrder();
        $order->setCustomFields([OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT => 'denied']);

        $provider = $this->provider($order, ['consentMode' => 'all']);

        self::assertNull($provider->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext()));
    }

    public function testServerSideConsentGrantGetsContext(): void
    {
        $order = $this->paidOrder();
        $order->setCustomFields([OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT => 'granted']);

        $provider = $this->provider($order, ['consentMode' => 'all']);

        self::assertNotNull($provider->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext()));
    }

    public function testOrderOfAnotherSalesChannelGetsNoContext(): void
    {
        $order = $this->paidOrder();

        self::assertNull($this->provider($order)->forOrder(OrderFixtures::ORDER_ID, 'other-channel', Context::createDefaultContext()));
    }

    public function testUnknownOrderGetsNoContext(): void
    {
        self::assertNull($this->provider(null)->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext()));
    }

    public function testOrderIsReloadedWithTheTransactionState(): void
    {
        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria) use (&$captured): EntitySearchResult {
            $captured = $criteria;

            return $this->searchResult(null, $criteria);
        });

        $this->providerWith($repository, [])->forOrder(OrderFixtures::ORDER_ID, self::SALES_CHANNEL, Context::createDefaultContext());

        self::assertInstanceOf(Criteria::class, $captured);
        self::assertSame([OrderFixtures::ORDER_ID], $captured->getIds());
        self::assertTrue($captured->hasAssociation('transactions'));
        self::assertTrue($captured->getAssociation('transactions')->hasAssociation('stateMachineState'));
    }

    private function paidOrder(): OrderEntity
    {
        return OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('paid')]);
    }

    /** @param array<string, mixed> $settings */
    private function provider(?OrderEntity $order, array $settings = []): PurchaseContextProvider
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(fn (Criteria $criteria): EntitySearchResult => $this->searchResult($order, $criteria));

        return $this->providerWith($repository, $settings);
    }

    /** @param array<string, mixed> $settings */
    private function providerWith(EntityRepository $repository, array $settings): PurchaseContextProvider
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturnCallback(static function (string $key) use ($settings) {
            $name = substr($key, strlen('AxitraceShopware6.config.'));

            return $settings[$name] ?? ($name === 'pinterestCatalogIdMode' ? 'product_number_lowercase' : null);
        });
        $config = new PluginConfig($configService, new AxitraceCrypto('secret'), $this->createStub(LoggerInterface::class));

        return new PurchaseContextProvider(
            $repository,
            $config,
            new ConsentGate(),
            new CustomerGroupExclusion($config),
            new OrderEventNormalizer(),
            new PinterestCatalogIdResolver(),
            new PaidTransactionResolver(),
            new UuidV5Generator(),
        );
    }

    private function searchResult(?OrderEntity $order, Criteria $criteria): EntitySearchResult
    {
        $orders = new OrderCollection($order !== null ? [$order] : []);

        return new EntitySearchResult('order', $orders->count(), $orders, null, $criteria, Context::createDefaultContext());
    }
}
