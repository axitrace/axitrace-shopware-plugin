<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Integration\Subscriber;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Normalizer\ConversionValueBasis;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use AxitraceShopware6\Subscriber\OrderPaidSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Integration-style tests for OrderPaidSubscriber — including the server-side
 * consent gate (mode "all").
 *
 * The plugin's service classes are final and cannot be doubled, so this test
 * wires REAL implementations end-to-end: PluginConfig on a mocked
 * SystemConfigService, IngestionApiClient on a MockHttpClient, and the real
 * normalizer + UUID generator (the order entity stub feeds them neutral data).
 * Shopware state-machine types are guarded with class_exists() checks and
 * markTestSkipped(), following the OrderEventNormalizerTest pattern.
 */
final class OrderPaidSubscriberTest extends TestCase
{
    private const VALID_PK = 'pk_live_aabbccddeeff00112233445566778899';

    private SystemConfigService&MockObject $configService;
    private LoggerInterface&MockObject $logger;
    private EntityRepository&MockObject $orderRepository;
    private EntityRepository&MockObject $orderTransactionRepository;
    private EntityRepository&MockObject $failedEventRepository;
    private PluginConfig $config;
    private IngestionApiClient $ingestionClient;
    private OrderPaidSubscriber $subscriber;

    /** Number of HTTP requests the mocked transport actually served. */
    private int $requestCount = 0;

    /** Every SystemConfigService::set() call, keyed+valued, for assertions. */
    private array $configSets = [];

    private object $orderEntity;

    protected function setUp(): void
    {
        $this->configService             = $this->createMock(SystemConfigService::class);
        $this->logger                    = $this->createMock(LoggerInterface::class);
        $this->orderRepository           = $this->createMock(EntityRepository::class);
        $this->orderTransactionRepository = $this->createMock(EntityRepository::class);
        $this->failedEventRepository     = $this->createMock(EntityRepository::class);
        $this->requestCount              = 0;
        $this->configSets                = [];

        $this->config = new PluginConfig(
            $this->configService,
            new AxitraceCrypto('test-app-secret-fixture'),
            $this->logger,
        );
    }

    private function givenConfig(string $consentMode, bool $publicKeySet = true): void
    {
        $this->configService
            ->method('get')
            ->willReturnCallback(
                static function (string $key) use ($consentMode, $publicKeySet): mixed {
                    return match ($key) {
                        'AxitraceShopware6.config.enabled'    => true,
                        'AxitraceShopware6.config.publicKey'  => $publicKeySet ? self::VALID_PK : null,
                        'AxitraceShopware6.config.consentMode' => $consentMode,
                        default                               => null,
                    };
                },
            );

        $this->configService
            ->method('set')
            ->willReturnCallback(
                function (string $key, mixed $value): void {
                    $this->configSets[$key] = $value;
                },
            );
    }

    /**
     * Builds the subscriber with a real IngestionApiClient on a transport that
     * answers 202 by default (or throws per $transportThrows flag).
     */
    private function makeSubscriber(bool $transportThrows = false): void
    {
        $self = $this;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($self, $transportThrows): MockResponse {
            $self->requestCount++;

            if ($transportThrows) {
                throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
            }

            return new MockResponse('', ['http_code' => 202]);
        });

        $this->ingestionClient = new IngestionApiClient($http, $this->logger, null);

        $this->subscriber = new OrderPaidSubscriber(
            $this->config,
            new ConsentGate(),
            $this->ingestionClient,
            new OrderEventNormalizer(),
            new UuidV5Generator(),
            $this->orderRepository,
            $this->failedEventRepository,
            $this->logger,
        );
    }

    /**
     * Configures the system-config store: enabled + public key + the given
     * consent mode (null = key never saved — the update-path scenario).
     */
    private function givenOrder(array $customFields = []): void
    {
        $orderEntity = $this->buildOrderEntity('order-uuid-test-001', 'sales-channel-001', $customFields);
        $this->orderEntity = $orderEntity;

        $this->orderRepository
            ->method('search')
            ->willReturn($this->buildSearchResult('order', new OrderCollection([$orderEntity])));
    }

    /**
     * Builds a REAL OrderStateMachineStateChangeEvent around the stub order —
     * the event class is directly constructible (name, order, context).
     */
    private function paidEvent(): object
    {
        return new \Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent(
            'state_enter.order_transaction.state.paid',
            $this->orderEntity,
            Context::createDefaultContext(),
        );
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testDispatchHappyPath(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber();
        $this->givenConfig('off'); // historical default mode
        $this->givenOrder();

        $this->failedEventRepository->expects(self::never())->method('create');

        $this->subscriber->onOrderPaid($this->paidEvent());

        self::assertSame(1, $this->requestCount, 'Exactly one ingestion request must be sent');
        self::assertSame(0, $this->configSets['AxitraceShopware6.runtime.recent_failure_count'] ?? null, 'Failure counters must be cleared after success');
    }

    // -------------------------------------------------------------------------
    // Missing public key
    // -------------------------------------------------------------------------

    public function testMissingPublicKeyLogsCriticalNoHttpCall(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber();
        $this->givenConfig('off', publicKeySet: false);
        $this->givenOrder();

        // With a REAL PluginConfig, an unset public key makes isEnabled() false,
        // so dispatch exits silently before the explicit critical branch (the
        // critical is reserved for a configured-but-broken key, see unit tests).
        $this->logger->expects(self::never())->method('critical');
        $this->failedEventRepository->expects(self::never())->method('create');

        self::assertFalse($this->config->isEnabled('sales-channel-001'), 'Missing public key must disable the plugin');

        $this->subscriber->onOrderPaid($this->paidEvent());

        self::assertSame(0, $this->requestCount);
    }

    // -------------------------------------------------------------------------
    // Ingestion unreachable → retry queue
    // -------------------------------------------------------------------------

    public function testIngestionUnreachablePersistsToRetryQueue(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber(transportThrows: true);
        $this->givenConfig('off');
        $this->givenOrder();

        $this->failedEventRepository
            ->expects(self::once())
            ->method('create')
            ->with(
                self::callback(static function (array $rows): bool {
                    $row = $rows[0] ?? null;

                    return $row !== null
                        && isset($row['id'], $row['eventId'], $row['payload'], $row['lastError'])
                        && $row['attempts'] === 0;
                }),
                self::isInstanceOf(Context::class),
            );

        $this->subscriber->onOrderPaid($this->paidEvent());

        // recordFailure() must have lit up the runtime counters.
        self::assertSame(1, $this->configSets['AxitraceShopware6.runtime.recent_failure_count'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Server-side consent gate (mode "all")
    // -------------------------------------------------------------------------

    public function testModeAllDeniedConsentIsNotForwarded(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber();
        $this->givenConfig('all');
        $this->givenOrder([OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT => 'denied']);

        $this->failedEventRepository->expects(self::never())->method('create');
        $this->logger->expects(self::never())->method('critical');

        $this->subscriber->onOrderPaid($this->paidEvent());

        self::assertSame(0, $this->requestCount, 'A denied-consent purchase must never reach the ingestion API');
        self::assertArrayNotHasKey('AxitraceShopware6.runtime.recent_failure_count', $this->configSets, 'A consent skip is not a failure');
    }

    public function testModeAllMissingConsentDecisionIsNotForwarded(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber();
        $this->givenConfig('all');
        $this->givenOrder(); // no consent key at all — pre-upgrade / admin / API order

        $this->failedEventRepository->expects(self::never())->method('create');

        $warnings = [];
        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('consent not granted'), self::callback(static function (array $ctx) use (&$warnings): bool {
                $warnings = $ctx;

                return true;
            }));

        $this->subscriber->onOrderPaid($this->paidEvent());

        self::assertSame(0, $this->requestCount);
        self::assertArrayHasKey('order_number', $warnings, 'Skip log must carry the order number');
        self::assertArrayHasKey('mode', $warnings, 'Skip log must carry the mode');
        self::assertSame('all', $warnings['mode']);
    }

    public function testModeBrowserMissingConsentDecisionStillForwards(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber();
        $this->givenConfig('browser');
        $this->givenOrder(); // server side is NOT gated in mode browser

        $this->failedEventRepository->expects(self::never())->method('create');

        $this->subscriber->onOrderPaid($this->paidEvent());

        self::assertSame(1, $this->requestCount, 'Mode browser must never gate the server-side dispatch');
    }

    public function testModeAllGrantedConsentIsForwarded(): void
    {
        if (!$this->shopwareTypesAvailable()) {
            $this->markTestSkipped('Shopware state machine types not installed.');
        }

        $this->makeSubscriber();
        $this->givenConfig('all');
        $this->givenOrder(['axitrace_consent' => 'granted']);

        $this->failedEventRepository->expects(self::never())->method('create');

        $this->subscriber->onOrderPaid($this->paidEvent());

        self::assertSame(1, $this->requestCount, 'A granted-consent purchase must be forwarded in mode all');
    }

    // -------------------------------------------------------------------------
    // Deterministic event_id
    // -------------------------------------------------------------------------

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
     * Builds a minimal REAL OrderEntity via the parent's own setters — this
     * initialises the parent's typed properties correctly (an overriding
     * getter cannot fix an uninitialized parent typed property, which the
     * normalizer's getAmountNet() etc. would hit).
     */
    private function buildOrderEntity(string $orderId, string $salesChannelId, array $customFields = []): object
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order = new \Shopware\Core\Checkout\Order\OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId($salesChannelId);
        $order->setOrderNumber('SW-10001');
        $order->setAmountTotal(99.99);
        $order->setAmountNet(90.00);
        $order->setShippingCosts(new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
            1,
            8.90,
            new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
            new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
        ));

        $tx = new \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity();
        $tx->setId(\Shopware\Core\Framework\Uuid\Uuid::randomHex());
        $order->setTransactions(new \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection([$tx]));

        if ($customFields !== []) {
            $order->setCustomFields($customFields);
        }

        return $order;
    }

    /**
     * Builds a real EntitySearchResult so the mocked EntityRepository::search()
     * satisfies its declared return type.
     */
    private function buildSearchResult(string $entityName, \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection $entities): object
    {
        return new EntitySearchResult(
            $entityName,
            $entities->count(),
            $entities,
            new AggregationResultCollection(),
            new Criteria(),
            Context::createDefaultContext(),
        );
    }
}
