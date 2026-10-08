<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Subscriber;

use AxitraceShopware6\ClickId\PersistedClickIdReader;
use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * The consent decision recorded on the order at placement time.
 *
 * Why this exists: before 0.3.0 the subscriber stamped "denied" on every order
 * whose AxiTrace consent cookie was absent. On a store with no cookie banner,
 * and for a visitor who had not answered one yet, that is an invented refusal:
 * it reaches AxiTrace as an explicit "the shopper said no" and, with the
 * workspace consent policy on, strips the purchase of its ad identifiers. From
 * 0.3.0 a refusal needs proof - Shopware's own cookie-preference marker, which
 * exists only once the visitor saved a choice in the cookie configuration.
 *
 * The plugin's services are final and cannot be doubled, so this wires the real
 * PluginConfig and ConsentGate and doubles only the framework edges (the
 * SystemConfigService, the order repository and the event).
 */
final class OrderPlacedSubscriberConsentTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d2c1ce7a2ba9d1f2f2c8b1a001';
    private const ORDER_ID = '0191d3d2c1ce7a2ba9d1f2f2c8b1a002';

    /** A valid _fbp so the subscriber always has something to persist. */
    private const VALID_FBP = 'fb.1.1755500000000.1234567890';

    private LoggerInterface&MockObject $logger;
    private EntityRepository&MockObject $orderRepository;
    private OrderPlacedSubscriber $subscriber;
    private RequestStack $requestStack;

    /** customFields of every EntityRepository::update() payload seen. */
    private array $updates = [];

    protected function setUp(): void
    {
        $configService = $this->createMock(SystemConfigService::class);
        // Empty consentCookie: PluginConfig falls back to the default cookie name.
        $configService->method('get')->willReturn('');

        $this->logger = $this->createMock(LoggerInterface::class);
        // A capture failure is swallowed and logged critical, which would make a
        // broken subscriber look like a passing "no stamp" case. Fail loudly instead.
        $this->logger->expects(self::never())->method('critical');

        $this->updates = [];
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $written = $this->createMock(EntityWrittenContainerEvent::class);
        $this->orderRepository->method('update')->willReturnCallback(
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
            $this->orderRepository,
            $this->logger,
            new PluginConfig(
                $configService,
                new AxitraceCrypto('test-app-secret-fixture'),
                $this->logger,
            ),
            new ConsentGate(),
            new PersistedClickIdReader(),
        );
    }

    public function testNoBannerDecisionLeavesTheOrderWithoutAConsentStamp(): void
    {
        self::assertArrayNotHasKey(
            OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT,
            $this->whenOrderPlacedWithCookies(['_fbp' => self::VALID_FBP]),
            'An absent consent cookie without Shopware\'s cookie-preference marker proves nothing and must not be stamped as a refusal.',
        );
    }

    public function testAnsweredBannerWithoutTheConsentCookieIsStampedDenied(): void
    {
        $customFields = $this->whenOrderPlacedWithCookies([
            '_fbp' => self::VALID_FBP,
            OrderPlacedSubscriber::SHOPWARE_DECISION_COOKIE => '1',
        ]);

        self::assertSame(
            ConsentGate::DECISION_DENIED,
            $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null,
        );
    }

    public function testConsentCookiePresentIsStampedGranted(): void
    {
        $customFields = $this->whenOrderPlacedWithCookies([
            '_fbp' => self::VALID_FBP,
            ConsentGate::DEFAULT_CONSENT_COOKIE => '1',
        ]);

        self::assertSame(
            ConsentGate::DECISION_GRANTED,
            $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null,
        );
    }

    public function testConsentCookieWinsEvenWhenTheBannerMarkerIsPresent(): void
    {
        $customFields = $this->whenOrderPlacedWithCookies([
            '_fbp' => self::VALID_FBP,
            ConsentGate::DEFAULT_CONSENT_COOKIE => '1',
            OrderPlacedSubscriber::SHOPWARE_DECISION_COOKIE => '1',
        ]);

        self::assertSame(
            ConsentGate::DECISION_GRANTED,
            $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null,
        );
    }

    public function testOtherBuyerContextIsStillCapturedWhenNoConsentIsStamped(): void
    {
        $customFields = $this->whenOrderPlacedWithCookies(['_fbp' => self::VALID_FBP]);

        self::assertSame(
            self::VALID_FBP,
            $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_FBP] ?? null,
        );
        self::assertSame(
            'https://shop.example/checkout/finish',
            $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_SOURCE_URL] ?? null,
        );
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string}>
     */
    public static function consentDecisionProvider(): iterable
    {
        yield 'no cookies at all' => [null, null, null];
        yield 'banner answered, consent refused' => [null, '1', ConsentGate::DECISION_DENIED];
        yield 'consent cookie present' => ['1', null, ConsentGate::DECISION_GRANTED];
        yield 'consent cookie present after an answered banner' => ['1', '1', ConsentGate::DECISION_GRANTED];
        yield 'consent cookie kept but flipped to a deny value' => ['0', '1', ConsentGate::DECISION_DENIED];
        yield 'consent cookie flipped to deny, banner never answered' => ['0', null, null];
        yield 'empty marker proves nothing' => [null, '', null];
        yield 'blank marker proves nothing' => [null, '   ', null];
    }

    #[DataProvider('consentDecisionProvider')]
    public function testResolveConsentDecision(?string $consentCookie, ?string $marker, ?string $expected): void
    {
        self::assertSame($expected, $this->subscriber->resolveConsentDecision($consentCookie, $marker));
    }

    /**
     * Dispatches a placed order carrying exactly these cookies and returns the
     * customFields the subscriber persisted.
     *
     * @param array<string, string> $cookies
     *
     * @return array<string, string>
     */
    private function whenOrderPlacedWithCookies(array $cookies): array
    {
        $request = Request::create('https://shop.example/checkout/finish');
        foreach ($cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $this->requestStack->push($request);

        $this->subscriber->onOrderPlaced($this->givenOrderPlacedEvent());

        self::assertCount(1, $this->updates, 'The subscriber must persist exactly one order update.');

        return $this->updates[0];
    }

    private function givenOrderPlacedEvent(): CheckoutOrderPlacedEvent
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn(self::ORDER_ID);

        $event = $this->createMock(CheckoutOrderPlacedEvent::class);
        $event->method('getOrder')->willReturn($order);
        $event->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        return $event;
    }
}
