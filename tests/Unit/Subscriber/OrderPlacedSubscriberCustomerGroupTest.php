<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Subscriber;

use AxitraceShopware6\ClickId\PersistedClickIdReader;
use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Exclusion\CustomerGroupExclusion;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The customer group is recorded on the order at placement, so the paid
 * transition judges the order by the group the customer ordered in.
 */
final class OrderPlacedSubscriberCustomerGroupTest extends TestCase
{
    private const ORDER_ID = '0191d3d2c1ce7a2ba9d1f2f2c8b1a002';
    private const B2B_GROUP = '0191D3D2C1CE7A2BA9D1F2F2C8B1AB2B';

    /** @var list<array<string, mixed>> customFields of every order update */
    private array $updates = [];

    private RequestStack $requestStack;
    private OrderPlacedSubscriber $subscriber;

    protected function setUp(): void
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturn('');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');

        $this->updates = [];
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('update')->willReturnCallback(function (array $payload): EntityWrittenContainerEvent {
            foreach ($payload as $row) {
                $this->updates[] = $row['customFields'] ?? [];
            }

            return $this->createMock(EntityWrittenContainerEvent::class);
        });

        $this->requestStack = new RequestStack();
        $this->subscriber = new OrderPlacedSubscriber(
            $this->requestStack,
            $orderRepository,
            $logger,
            new PluginConfig($configService, new AxitraceCrypto('test-app-secret-fixture'), $logger),
            new ConsentGate(),
            new PersistedClickIdReader(),
        );
    }

    public function testCustomerGroupIsRecordedLowercaseWithTheBuyerContext(): void
    {
        $this->requestStack->push(Request::create('https://shop.example/checkout/finish'));

        $this->subscriber->onOrderPlaced($this->event(static fn (): string => self::B2B_GROUP));

        self::assertCount(1, $this->updates);
        self::assertSame(strtolower(self::B2B_GROUP), $this->updates[0][CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP] ?? null);
        self::assertSame('https://shop.example/checkout/finish', $this->updates[0][OrderPlacedSubscriber::CUSTOM_FIELD_SOURCE_URL] ?? null);
    }

    public function testCustomerGroupIsRecordedForAnOrderPlacedWithoutARequest(): void
    {
        $this->subscriber->onOrderPlaced($this->event(static fn (): string => self::B2B_GROUP));

        self::assertSame([[CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP => strtolower(self::B2B_GROUP)]], $this->updates);
    }

    public function testOrderWithoutACustomerIsPlacedWithoutAGroupAndWithoutAnError(): void
    {
        $this->requestStack->push(Request::create('https://shop.example/checkout/finish'));

        $this->subscriber->onOrderPlaced($this->event(static fn (): string => throw CartException::orderCustomerDeleted(self::ORDER_ID)));

        self::assertCount(1, $this->updates);
        self::assertArrayNotHasKey(CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP, $this->updates[0]);
    }

    /** @param \Closure(): string $customerGroupId */
    private function event(\Closure $customerGroupId): CheckoutOrderPlacedEvent
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn(self::ORDER_ID);

        $event = $this->createMock(CheckoutOrderPlacedEvent::class);
        $event->method('getOrder')->willReturn($order);
        $event->method('getSalesChannelId')->willReturn('0191d3d2c1ce7a2ba9d1f2f2c8b1a001');
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getCustomerGroupId')->willReturnCallback($customerGroupId);

        return $event;
    }
}
