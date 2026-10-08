<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Subscriber;

use AxitraceShopware6\ClickId\PersistedClickIdReader;
use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use AxitraceShopware6\Tests\Unit\Normalizer\OrderFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ad click ids captured in the buyer's checkout request, persisted on the order, and
 * read back by the normalizer when the purchase is sent later from the "paid"
 * transition (async payment callback, admin action), which has no cookies.
 *
 * Why this exists: through 0.5.0 the order carried no gclid, gbraid, wbraid, ttclid,
 * oppref or obref at all, and rdt_cid in its raw cookie format "v2|<firstSeenMs>|<id>".
 */
final class OrderPlacedSubscriberClickIdTest extends TestCase
{
    private const NOW_MS = 1_791_460_800_000;
    private const DAY_MS = 86_400_000;
    private const ORDER_ID = '0191d3d2c1ce7a2ba9d1f2f2c8b1a003';

    private OrderPlacedSubscriber $subscriber;
    private RequestStack $requestStack;

    /** customFields of every EntityRepository::update() payload seen. */
    private array $updates = [];

    protected function setUp(): void
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturn('');

        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        // A capture failure is swallowed and logged critical; fail loudly instead.
        $logger->expects(self::never())->method('critical');

        $this->updates = [];
        $orderRepository = $this->createMock(EntityRepository::class);
        $written = $this->createMock(EntityWrittenContainerEvent::class);
        $orderRepository->method('update')->willReturnCallback(
            function (array $payload) use ($written): EntityWrittenContainerEvent {
                foreach ($payload as $row) {
                    $this->updates[] = $row['customFields'] ?? [];
                }

                return $written;
            },
        );

        $this->requestStack = new RequestStack();
        $this->subscriber = new OrderPlacedSubscriber(
            $this->requestStack,
            $orderRepository,
            $logger,
            new PluginConfig($configService, new AxitraceCrypto('test-app-secret-fixture'), $logger),
            new ConsentGate(),
            new PersistedClickIdReader(static fn (): int => self::NOW_MS),
        );
    }

    public function testEveryClickIdCookieIsPersistedAsTheBareId(): void
    {
        $fresh = self::NOW_MS - 2 * self::DAY_MS;
        $customFields = $this->whenOrderPlaced([], [
            '_gclid' => "v2|{$fresh}|Cj0KCQjw-gclid_1",
            '_gbraid' => "v2|{$fresh}|0AAAAAgbraid_1",
            '_wbraid' => "v2|{$fresh}|CkwKwbraid_1",
            '_ttclid' => "v2|{$fresh}|E.C.P.ttclid_1",
            '_rdt_cid' => "v2|{$fresh}|t2_rdt_cid_1",
            '_oppref' => "v2|{$fresh}|oppref_1",
            '_axi_msclkid' => "v2|{$fresh}|msclkid_1",
            '_axi_twclid' => "v2|{$fresh}|twclid_1",
            '_axi_epik' => "v2|{$fresh}|epik_1",
            '_axi_li_fat_id' => "v2|{$fresh}|li_fat_id_1",
            '_axi_sccid' => "v2|{$fresh}|sccid_1",
            '__obref' => 'obref.browser-ref_1',
        ]);

        self::assertSame('Cj0KCQjw-gclid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_GCLID] ?? null);
        self::assertSame('0AAAAAgbraid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_GBRAID] ?? null);
        self::assertSame('CkwKwbraid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_WBRAID] ?? null);
        self::assertSame('E.C.P.ttclid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_TTCLID] ?? null);
        self::assertSame('t2_rdt_cid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_RDT_CID] ?? null);
        self::assertSame('oppref_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_OPPREF] ?? null);
        self::assertSame('obref.browser-ref_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_OBREF] ?? null);
        self::assertSame('msclkid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_MSCLKID] ?? null);
        self::assertSame('twclid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_TWCLID] ?? null);
        self::assertSame('epik_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_EPIK] ?? null);
        self::assertSame('li_fat_id_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_LI_FAT_ID] ?? null);
        self::assertSame('sccid_1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_SCCID] ?? null);
    }

    /**
     * Click ids the web SDK never stored (not loaded on the landing page, or older than
     * 0.24.0) still reach the order from the cookies the platforms' own tags write, and
     * Snap's capitalised URL parameter is read on the checkout request.
     */
    public function testPlatformCookiesAndScCidArePersistedAndSentLater(): void
    {
        $customFields = $this->whenOrderPlaced(['ScCid' => 'snap-url-1'], [
            '_uetmsclkid' => '_uetmsclkid-vendor-1',
            '_twclid' => '{"twclid":"twclid-vendor-1","timestamp":1791460000000}',
            '_epik' => 'epik-vendor-1',
            'li_fat_id' => 'li-fat-vendor-1',
        ]);

        self::assertSame('msclkid-vendor-1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_MSCLKID] ?? null);
        self::assertSame('twclid-vendor-1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_TWCLID] ?? null);
        self::assertSame('epik-vendor-1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_EPIK] ?? null);
        self::assertSame('li-fat-vendor-1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_LI_FAT_ID] ?? null);
        self::assertSame('snap-url-1', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_SCCID] ?? null);

        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(null))]);
        $order->setCustomFields($customFields);
        $data = (new OrderEventNormalizer())->normalize($order, 'evt-vendor-ids', 'pk_test')['data'];

        self::assertSame('msclkid-vendor-1', $data['msclkid'] ?? null);
        self::assertSame('twclid-vendor-1', $data['twclid'] ?? null);
        self::assertSame('epik-vendor-1', $data['epik'] ?? null);
        self::assertSame('li-fat-vendor-1', $data['li_fat_id'] ?? null);
        self::assertSame('snap-url-1', $data['sccid'] ?? null);
    }

    public function testUrlClickIdWinsOverTheCookieOnTheCheckoutRequest(): void
    {
        $fresh = self::NOW_MS - self::DAY_MS;
        $customFields = $this->whenOrderPlaced(
            ['gclid' => 'url-gclid', 'ttclid' => 'url-ttclid'],
            ['_gclid' => "v2|{$fresh}|cookie-gclid", '_ttclid' => "v2|{$fresh}|cookie-ttclid"],
        );

        self::assertSame('url-gclid', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_GCLID] ?? null);
        self::assertSame('url-ttclid', $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_TTCLID] ?? null);
    }

    public function testExpiredAndMalformedClickIdCookiesAreNotPersisted(): void
    {
        $expired90 = self::NOW_MS - 91 * self::DAY_MS;
        $expired28 = self::NOW_MS - 29 * self::DAY_MS;
        $customFields = $this->whenOrderPlaced([], [
            '_fbp' => 'fb.1.1755500000000.1234567890',
            '_gclid' => "v2|{$expired90}|old-gclid",
            '_ttclid' => 'legacy-unversioned-ttclid',
            '_rdt_cid' => "v2|{$expired28}|old-rdt",
            '_oppref' => 'v2|abc|bad',
            '__obref' => 'has a space',
            '_axi_epik' => 'v2|' . (self::NOW_MS - 61 * self::DAY_MS) . '|old-epik',
            '_axi_li_fat_id' => 'v2|' . (self::NOW_MS - 31 * self::DAY_MS) . '|old-li-fat',
            '_axi_sccid' => 'v2|' . (self::NOW_MS - 29 * self::DAY_MS) . '|old-sccid',
            '_axi_msclkid' => 'legacy-unversioned-msclkid',
        ]);

        foreach ([
            OrderPlacedSubscriber::CUSTOM_FIELD_GCLID,
            OrderPlacedSubscriber::CUSTOM_FIELD_TTCLID,
            OrderPlacedSubscriber::CUSTOM_FIELD_RDT_CID,
            OrderPlacedSubscriber::CUSTOM_FIELD_OPPREF,
            OrderPlacedSubscriber::CUSTOM_FIELD_OBREF,
            OrderPlacedSubscriber::CUSTOM_FIELD_EPIK,
            OrderPlacedSubscriber::CUSTOM_FIELD_LI_FAT_ID,
            OrderPlacedSubscriber::CUSTOM_FIELD_SCCID,
            OrderPlacedSubscriber::CUSTOM_FIELD_MSCLKID,
        ] as $customField) {
            self::assertArrayNotHasKey($customField, $customFields);
        }
    }

    /**
     * The round trip that matters in production: captured in the checkout request,
     * persisted on the order, sent later by the normalizer as flat bare `data` keys.
     */
    public function testPersistedClickIdsAreReadBackByTheNormalizer(): void
    {
        $fresh = self::NOW_MS - self::DAY_MS;
        $customFields = $this->whenOrderPlaced(['wbraid' => 'url-wbraid'], [
            '_gclid' => "v2|{$fresh}|gclid-rt",
            '_gbraid' => "v2|{$fresh}|gbraid-rt",
            '_ttclid' => "v2|{$fresh}|ttclid-rt",
            '_rdt_cid' => "v2|{$fresh}|rdt-cid-rt",
            '_oppref' => "v2|{$fresh}|oppref-rt",
            '__obref' => 'obref-rt',
        ]);

        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(null))]);
        $order->setCustomFields($customFields);

        $data = (new OrderEventNormalizer())->normalize($order, 'evt-click-ids', 'pk_test')['data'];

        self::assertSame('gclid-rt', $data['gclid'] ?? null);
        self::assertSame('gbraid-rt', $data['gbraid'] ?? null);
        self::assertSame('url-wbraid', $data['wbraid'] ?? null);
        self::assertSame('ttclid-rt', $data['ttclid'] ?? null);
        self::assertSame('rdt-cid-rt', $data['rdt_cid'] ?? null);
        self::assertSame('oppref-rt', $data['oppref'] ?? null);
        self::assertSame('obref-rt', $data['obref'] ?? null);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     *
     * @return array<string, string>
     */
    private function whenOrderPlaced(array $query, array $cookies): array
    {
        $request = Request::create('https://shop.example/checkout/order', 'POST', $query);
        $request->query->replace($query);
        foreach ($cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $this->requestStack->push($request);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn(self::ORDER_ID);

        $event = $this->createMock(CheckoutOrderPlacedEvent::class);
        $event->method('getOrder')->willReturn($order);
        $event->method('getSalesChannelId')->willReturn('0191d3d2c1ce7a2ba9d1f2f2c8b1a001');
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        $this->subscriber->onOrderPlaced($event);

        self::assertCount(1, $this->updates, 'The subscriber must persist exactly one order update.');

        return $this->updates[0];
    }
}
