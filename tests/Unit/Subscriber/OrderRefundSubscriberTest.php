<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Subscriber;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\RefundEventNormalizer;
use AxitraceShopware6\ScheduledTask\FailedEventQueue;
use AxitraceShopware6\Subscriber\OrderRefundSubscriber;
use AxitraceShopware6\Tests\Unit\Normalizer\OrderFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Refunds reach `/v1/refund` only with the secret key, never break the state
 * machine, and fall back to the retry queue when the API is unreachable.
 * Real collaborators on mocked Shopware services, as in OrderPaidSubscriberTest.
 */
final class OrderRefundSubscriberTest extends TestCase
{
    private const VALID_PK = 'pk_live_aabbccddeeff00112233445566778899';
    private const VALID_SK = 'sk_' . 'live_0123456789abcdef0123456789abcdef';

    private LoggerInterface&MockObject $logger;
    private EntityRepository&MockObject $orderRepository;
    private EntityRepository&MockObject $failedEventRepository;

    /** @var list<array{url: string, headers: array<string, list<string>>, body: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->failedEventRepository = $this->createMock(EntityRepository::class);
        $this->requests = [];
    }

    private function subscriber(?string $secretKey, bool $transportThrows = false, string $consentMode = 'off', int $status = 202): OrderRefundSubscriber
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'AxitraceShopware6.config.enabled'     => true,
            'AxitraceShopware6.config.publicKey'   => self::VALID_PK,
            'AxitraceShopware6.config.secretKey'   => $secretKey,
            'AxitraceShopware6.config.consentMode' => $consentMode,
            default                                => null,
        });

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($transportThrows, $status): MockResponse {
            $this->requests[] = [
                'url'     => $url,
                'headers' => $options['normalized_headers'] ?? [],
                'body'    => json_decode((string) ($options['body'] ?? '{}'), true),
            ];
            if ($transportThrows) {
                throw new TransportException('connection refused');
            }

            return new MockResponse('', ['http_code' => $status]);
        });

        return new OrderRefundSubscriber(
            new PluginConfig($systemConfig, new AxitraceCrypto('test-app-secret-fixture'), $this->logger),
            new ConsentGate(),
            new IngestionApiClient($http, $this->logger, null),
            new RefundEventNormalizer(),
            $this->orderRepository,
            new FailedEventQueue($this->failedEventRepository, $this->logger),
            $this->logger,
        );
    }

    private function givenOrder(OrderEntity $order): void
    {
        $this->orderRepository->method('search')->willReturn(new EntitySearchResult(
            'order',
            1,
            new OrderCollection([$order]),
            new AggregationResultCollection(),
            new Criteria(),
            Context::createDefaultContext(),
        ));
    }

    private function refundedOrder(): OrderEntity
    {
        return OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded', 119.0)]);
    }

    private function stateEvent(string $name, OrderEntity $order): OrderStateMachineStateChangeEvent
    {
        return new OrderStateMachineStateChangeEvent($name, $order, Context::createDefaultContext());
    }

    public function testSubscribesToRefundAndCancellationStates(): void
    {
        $events = OrderRefundSubscriber::getSubscribedEvents();

        self::assertArrayHasKey('state_enter.order_transaction.state.refunded', $events);
        self::assertArrayHasKey('state_enter.order_transaction.state.refunded_partially', $events);
        self::assertArrayHasKey('state_enter.order.state.cancelled', $events);
        self::assertArrayHasKey(StateMachineTransitionEvent::class, $events);
    }

    public function testRefundIsSentWithTheSecretKey(): void
    {
        $order = $this->refundedOrder();
        $this->givenOrder($order);

        $this->subscriber(self::VALID_SK)->onTransactionRefunded($this->stateEvent('state_enter.order_transaction.state.refunded', $order));

        self::assertCount(1, $this->requests);
        self::assertSame('https://stat.axitrace.com/v1/refund', $this->requests[0]['url']);
        self::assertSame(['Authorization: Basic ' . base64_encode(self::VALID_SK . ':')], $this->requests[0]['headers']['authorization'] ?? null);
        self::assertSame('10042', $this->requests[0]['body']['orderId'], 'the order number the purchase is stored under');
        self::assertFalse($this->requests[0]['body']['isCancellation']);
        self::assertSame(self::VALID_PK, $this->requests[0]['body']['workspace_public_key'], 'a secret key of another workspace must be refused, not booked there');
    }

    public function testNothingIsSentWithoutTheSecretKey(): void
    {
        $order = $this->refundedOrder();
        $this->givenOrder($order);
        $this->logger->expects(self::never())->method('critical');

        $this->subscriber(null)->onTransactionRefunded($this->stateEvent('state_enter.order_transaction.state.refunded', $order));

        self::assertSame([], $this->requests);
    }

    public function testOrderCancellationIsSentAsCancellation(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('paid')]);
        $this->givenOrder($order);

        $this->subscriber(self::VALID_SK)->onOrderCancelled($this->stateEvent('state_enter.order.state.cancelled', $order));

        self::assertCount(1, $this->requests);
        self::assertTrue($this->requests[0]['body']['isCancellation']);
        self::assertSame('order:' . OrderFixtures::ORDER_ID . ':cancelled', $this->requests[0]['body']['refundId']);
    }

    public function testRefundOfAnOrderWhosePurchaseWasHeldBackByConsentIsNotSent(): void
    {
        $order = $this->refundedOrder(); // no consent decision recorded
        $this->givenOrder($order);

        $this->subscriber(self::VALID_SK, consentMode: 'all')->onTransactionRefunded($this->stateEvent('state_enter.order_transaction.state.refunded', $order));

        self::assertSame([], $this->requests);
    }

    public function testPartialRefundWithoutAmountLogsAWarningAndSendsNothing(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded_partially')]);
        $this->givenOrder($order);
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('partial refund not reported'));

        $this->subscriber(self::VALID_SK)->onTransactionRefundedPartially($this->stateEvent('state_enter.order_transaction.state.refunded_partially', $order));

        self::assertSame([], $this->requests);
    }

    public function testUnreachableApiQueuesTheRefundUnderItsRefundId(): void
    {
        $order = $this->refundedOrder();
        $this->givenOrder($order);

        $this->failedEventRepository->expects(self::once())->method('create')->with(
            self::callback(static function (array $rows): bool {
                $stored = json_decode($rows[0]['payload'], true);

                return $rows[0]['eventId'] === 'tx:' . OrderFixtures::TRANSACTION_ID . ':refunded'
                    && $stored[FailedEventQueue::ENVELOPE_KEY] === ['endpoint' => 'refund', 'salesChannelId' => 'sales-channel-001']
                    && !str_contains($rows[0]['payload'], 'sk_live');
            }),
            self::isInstanceOf(Context::class),
        );

        $this->subscriber(self::VALID_SK, transportThrows: true)->onTransactionRefunded($this->stateEvent('state_enter.order_transaction.state.refunded', $order));
    }

    public function testRefundRejectedFor401IsNeitherQueuedNorResentWithoutTheKey(): void
    {
        $order = $this->refundedOrder();
        $this->givenOrder($order);

        $this->failedEventRepository->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('critical')->with(self::logicalAnd(
            self::stringContains('rejected (HTTP 401)'),
            self::logicalNot(self::stringContains('sk_live')),
        ));

        $this->subscriber(self::VALID_SK, status: 401)->onTransactionRefunded($this->stateEvent('state_enter.order_transaction.state.refunded', $order));

        self::assertCount(1, $this->requests);
        self::assertArrayHasKey('authorization', $this->requests[0]['headers']);
    }

    public function testAThrowingRepositoryNeverEscapesIntoTheStateMachine(): void
    {
        $this->orderRepository->method('search')->willThrowException(new \RuntimeException('db gone'));
        $this->logger->expects(self::once())->method('critical')->with(self::stringContains('refund dispatch failed'));

        $this->subscriber(self::VALID_SK)->onOrderCancelled($this->stateEvent('state_enter.order.state.cancelled', $this->refundedOrder()));
    }

    public function testPaidTransactionCancelledDirectlyIsSentAsCancellation(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('cancelled')]);
        $this->givenOrder($order);

        $this->subscriber(self::VALID_SK)->onTransition(new StateMachineTransitionEvent(
            'order_transaction',
            OrderFixtures::TRANSACTION_ID,
            OrderFixtures::state('paid'),
            OrderFixtures::state('cancelled'),
            Context::createDefaultContext(),
        ));

        self::assertCount(1, $this->requests);
        self::assertTrue($this->requests[0]['body']['isCancellation']);
    }

    public function testUnrelatedTransitionsDoNotQuery(): void
    {
        $this->orderRepository->expects(self::never())->method('search');
        $subscriber = $this->subscriber(self::VALID_SK);

        foreach ([['order_transaction', 'open', 'cancelled'], ['order_transaction', 'open', 'paid'], ['order_delivery', 'paid', 'cancelled']] as [$entity, $from, $to]) {
            $subscriber->onTransition(new StateMachineTransitionEvent(
                $entity,
                OrderFixtures::TRANSACTION_ID,
                OrderFixtures::state($from),
                OrderFixtures::state($to),
                Context::createDefaultContext(),
            ));
        }

        self::assertSame([], $this->requests);
    }
}
