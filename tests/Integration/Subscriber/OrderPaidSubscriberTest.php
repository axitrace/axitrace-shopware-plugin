<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Integration\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\Subscriber\OrderPaidSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Integration-style tests for OrderPaidSubscriber.
 *
 * All Shopware classes are mocked or stubbed so these tests run without a
 * full Shopware installation.  Each test guards against missing Shopware types
 * with class_exists() checks and calls markTestSkipped() when necessary,
 * following the same pattern used in OrderEventNormalizerTest.
 */
final class OrderPaidSubscriberTest extends TestCase
{
    private PluginConfig&MockObject $config;
    private IngestionApiClient&MockObject $ingestionClient;
    private OrderEventNormalizer&MockObject $normalizer;
    private UuidV5Generator&MockObject $uuidGenerator;
    private EntityRepository&MockObject $orderRepository;
    private EntityRepository&MockObject $orderTransactionRepository;
    private EntityRepository&MockObject $failedEventRepository;
    private LoggerInterface&MockObject $logger;
    private OrderPaidSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config                    = $this->createMock(PluginConfig::class);
        $this->ingestionClient           = $this->createMock(IngestionApiClient::class);
        $this->normalizer                = $this->createMock(OrderEventNormalizer::class);
        $this->uuidGenerator             = $this->createMock(UuidV5Generator::class);
        $this->orderRepository           = $this->createMock(EntityRepository::class);
        $this->orderTransactionRepository = $this->createMock(EntityRepository::class);
        $this->failedEventRepository     = $this->createMock(EntityRepository::class);
        $this->logger                    = $this->createMock(LoggerInterface::class);

        $this->subscriber = new OrderPaidSubscriber(
            $this->config,
            $this->ingestionClient,
            $this->normalizer,
            $this->uuidGenerator,
            $this->orderRepository,
            $this->orderTransactionRepository,
            $this->failedEventRepository,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------------
    // Test 1: Happy path — sendEvent called once, clearFailureCounters called
    // -------------------------------------------------------------------------

    /**
     * Full happy path: valid order found → sendEvent called once → counters cleared.
     */
    public function testDispatchHappyPath(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $orderId       = 'order-uuid-happy-001';
        $transactionId = 'tx-uuid-happy-001';
        $salesChannelId = 'sales-channel-001';
        $publicKey     = 'pk_live_aabbccddeeff00112233445566778899';
        $eventId       = 'a86a3492-94e6-585c-bea8-fe689277774a';

        $event = $this->buildStateMachineEvent($transactionId);

        $txEntity    = $this->buildTransactionEntity($orderId);
        $orderEntity = $this->buildOrderEntity($orderId, $salesChannelId);

        $this->orderTransactionRepository
            ->expects(self::once())
            ->method('search')
            ->willReturn($this->buildSearchResult([$txEntity]));

        $this->orderRepository
            ->expects(self::once())
            ->method('search')
            ->willReturn($this->buildSearchResult([$orderEntity]));

        $this->config->method('isEnabled')->with($salesChannelId)->willReturn(true);
        $this->config->method('getPublicKey')->with($salesChannelId)->willReturn($publicKey);

        $this->uuidGenerator
            ->expects(self::once())
            ->method('forOrder')
            ->with($orderId, $transactionId)
            ->willReturn($eventId);

        $fakePayload = ['event' => 'transaction.charge', 'event_id' => $eventId];
        $this->normalizer
            ->expects(self::once())
            ->method('normalize')
            ->willReturn($fakePayload);

        $this->ingestionClient
            ->expects(self::once())
            ->method('sendEvent')
            ->with($fakePayload);

        $this->config
            ->expects(self::once())
            ->method('clearFailureCounters')
            ->with($salesChannelId);

        $this->failedEventRepository->expects(self::never())->method('create');

        $this->subscriber->onOrderPaid($event);
    }

    // -------------------------------------------------------------------------
    // Test 2: Missing public key — critical log, no HTTP call
    // -------------------------------------------------------------------------

    /**
     * When getPublicKey() returns an empty string, sendEvent must NOT be called
     * and a critical log entry must be written.
     */
    public function testMissingPublicKeyLogsCriticalNoHttpCall(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $orderId        = 'order-uuid-nokey-001';
        $transactionId  = 'tx-uuid-nokey-001';
        $salesChannelId = 'sales-channel-nokey';

        $event       = $this->buildStateMachineEvent($transactionId);
        $txEntity    = $this->buildTransactionEntity($orderId);
        $orderEntity = $this->buildOrderEntity($orderId, $salesChannelId);

        $this->orderTransactionRepository
            ->method('search')
            ->willReturn($this->buildSearchResult([$txEntity]));

        $this->orderRepository
            ->method('search')
            ->willReturn($this->buildSearchResult([$orderEntity]));

        $this->config->method('isEnabled')->with($salesChannelId)->willReturn(true);
        $this->config->method('getPublicKey')->with($salesChannelId)->willReturn('');

        $this->logger
            ->expects(self::once())
            ->method('critical')
            ->with(self::stringContains('public key not configured'));

        $this->ingestionClient->expects(self::never())->method('sendEvent');
        $this->uuidGenerator->expects(self::never())->method('forOrder');
        $this->failedEventRepository->expects(self::never())->method('create');

        $this->subscriber->onOrderPaid($event);
    }

    // -------------------------------------------------------------------------
    // Test 3: IngestionUnreachableException — event persisted to retry queue
    // -------------------------------------------------------------------------

    /**
     * When sendEvent() throws IngestionUnreachableException, the row must be
     * persisted to the retry queue via failedEventRepository->create(), and
     * no exception must bubble out of onOrderPaid.
     */
    public function testIngestionUnreachablePersistsToRetryQueue(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $orderId        = 'order-uuid-retry-001';
        $transactionId  = 'tx-uuid-retry-001';
        $salesChannelId = 'sales-channel-retry';
        $publicKey      = 'pk_live_aabbccddeeff00112233445566778899';
        $eventId        = 'b96a3492-94e6-585c-bea8-fe689277774b';

        $event       = $this->buildStateMachineEvent($transactionId);
        $txEntity    = $this->buildTransactionEntity($orderId);
        $orderEntity = $this->buildOrderEntity($orderId, $salesChannelId);

        $this->orderTransactionRepository
            ->method('search')
            ->willReturn($this->buildSearchResult([$txEntity]));

        $this->orderRepository
            ->method('search')
            ->willReturn($this->buildSearchResult([$orderEntity]));

        $this->config->method('isEnabled')->with($salesChannelId)->willReturn(true);
        $this->config->method('getPublicKey')->with($salesChannelId)->willReturn($publicKey);

        $this->uuidGenerator->method('forOrder')->willReturn($eventId);

        $fakePayload = ['event' => 'transaction.charge', 'event_id' => $eventId];
        $this->normalizer->method('normalize')->willReturn($fakePayload);

        $this->ingestionClient
            ->expects(self::once())
            ->method('sendEvent')
            ->willThrowException(new IngestionUnreachableException('connection refused'));

        // The row must be created with the correct eventId
        $this->failedEventRepository
            ->expects(self::once())
            ->method('create')
            ->with(
                self::callback(static function (array $rows) use ($eventId): bool {
                    $row = $rows[0] ?? null;
                    return $row !== null
                        && isset($row['id'])
                        && $row['eventId'] === $eventId
                        && isset($row['payload'])
                        && $row['attempts'] === 0
                        && isset($row['lastError']);
                }),
                self::isInstanceOf(Context::class),
            );

        $this->config
            ->expects(self::once())
            ->method('recordFailure')
            ->with('connection refused', $salesChannelId);

        // Must not throw
        $this->subscriber->onOrderPaid($event);
    }

    // -------------------------------------------------------------------------
    // Test 4: Deterministic event_id — same inputs → same UUID v5
    // -------------------------------------------------------------------------

    /**
     * UuidV5Generator must produce identical event IDs for the same order +
     * transaction pair.  This verifies that the generator is called with the
     * composite (orderId, transactionId) key and that the result is stable
     * across calls.
     */
    public function testSameOrderAndTransactionProducesSameEventId(): void
    {
        $generator = new UuidV5Generator();

        $orderId       = '019503e4-1234-7abc-8def-0123456789ab';
        $transactionId = 'TRANS-UUID-HERE';

        $id1 = $generator->forOrder($orderId, $transactionId);
        $id2 = $generator->forOrder($orderId, $transactionId);

        self::assertSame($id1, $id2, 'UUID v5 must be deterministic for the same inputs.');
        // Must be RFC 4122 format
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id1,
            'event_id must be a valid RFC 4122 UUID v5.',
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function shopwareTypesAvailable(): bool
    {
        return class_exists(\Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent::class)
            && class_exists(\Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult::class)
            && class_exists(\Shopware\Core\Framework\Context::class);
    }

    /**
     * Builds a minimal StateMachineStateChangeEvent stub via anonymous class extension.
     */
    private function buildStateMachineEvent(string $transactionId): object
    {
        // Build a Transition stub
        $transition = new class ($transactionId) {
            /** @var string */
            private $id;

            public function __construct(string $id)
            {
                $this->id = $id;
            }

            public function getEntityName(): string
            {
                return \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition::ENTITY_NAME;
            }

            public function getEntityId(): string
            {
                return $this->id;
            }
        };

        $context = Context::createDefaultContext();

        return new class ($transition, $context) extends \Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent {
            /** @var object */
            private $transitionRef;
            /** @var \Shopware\Core\Framework\Context */
            private $contextRef;

            public function __construct(object $transition, \Shopware\Core\Framework\Context $context)
            {
                // Do not call parent::__construct() — it requires too many real SW objects.
                $this->transitionRef = $transition;
                $this->contextRef    = $context;
            }

            public function getTransition(): object
            {
                return $this->transitionRef;
            }

            public function getContext(): \Shopware\Core\Framework\Context
            {
                return $this->contextRef;
            }
        };
    }

    /**
     * Builds a minimal order transaction entity stub that exposes getOrderId().
     */
    private function buildTransactionEntity(string $orderId): object
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity::class)) {
            $this->markTestSkipped('Shopware OrderTransactionEntity not installed.');
        }

        $orderIdArg = $orderId;

        return new class ($orderIdArg) extends \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity {
            /** @var string */
            private $orderIdValue;

            public function __construct(string $orderId)
            {
                parent::__construct();
                $this->setId(\Shopware\Core\Framework\Uuid\Uuid::randomHex());
                $this->orderIdValue = $orderId;
            }

            public function getOrderId(): string
            {
                return $this->orderIdValue;
            }
        };
    }

    /**
     * Builds a minimal OrderEntity stub exposing getSalesChannelId() and getId().
     */
    private function buildOrderEntity(string $orderId, string $salesChannelId): object
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $orderIdArg       = $orderId;
        $salesChannelArg  = $salesChannelId;

        return new class ($orderIdArg, $salesChannelArg) extends \Shopware\Core\Checkout\Order\OrderEntity {
            /** @var string */
            private $salesChannelIdValue;

            public function __construct(string $orderId, string $salesChannelId)
            {
                parent::__construct();
                $this->setId($orderId);
                $this->salesChannelIdValue = $salesChannelId;
            }

            public function getSalesChannelId(): string
            {
                return $this->salesChannelIdValue;
            }

            public function getAmountTotal(): float
            {
                return 99.99;
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                return null;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                return null;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                return null;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                return null;
            }
        };
    }

    /**
     * Builds an EntitySearchResult wrapping the provided entity array.
     * Returns the first element from first() / null when the array is empty.
     */
    private function buildSearchResult(array $entities): object
    {
        return new class ($entities) {
            /** @var array<int, object> */
            private array $items;

            public function __construct(array $entities)
            {
                $this->items = $entities;
            }

            public function first(): ?object
            {
                return $this->items[0] ?? null;
            }
        };
    }
}
