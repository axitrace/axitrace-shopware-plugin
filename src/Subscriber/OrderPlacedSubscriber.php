<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Captures request-scoped buyer context at order placement time and persists it
 * onto the order's customFields: Meta browser pixel cookies (_fbp/_fbc), the
 * buyer's real IP address and User-Agent, and Google Analytics cookies
 * (_ga client id, _ga_<container> session).
 *
 * Why this exists: the purchase event itself is sent later, on the
 * order_transaction "paid" state transition (see OrderPaidSubscriber), which for
 * asynchronous payment methods (PayPal, redirect gateways, webhook confirmations)
 * runs OUTSIDE the customer's own request — no cookies are available there.
 * CheckoutOrderPlacedEvent fires synchronously within the customer's own checkout
 * request, so it is the only reliable point at which these cookies can be read.
 *
 * customFields is a native Shopware DAL field (no schema migration needed) and a
 * partial update via EntityRepository::update() merges keys rather than replacing
 * the whole object, so this cannot clobber custom fields set by other plugins.
 *
 * MUST NEVER throw — an uncaught exception here would abort the checkout entirely.
 */
final class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public const CUSTOM_FIELD_FBP = 'axitrace_fbp';
    public const CUSTOM_FIELD_FBC = 'axitrace_fbc';
    public const CUSTOM_FIELD_CLIENT_IP = 'axitrace_client_ip';
    public const CUSTOM_FIELD_CLIENT_UA = 'axitrace_client_ua';
    public const CUSTOM_FIELD_GA = 'axitrace_ga';
    public const CUSTOM_FIELD_GA_SESSION = 'axitrace_ga_session';

    /**
     * TikTok browser ID (_ttp), Reddit browser ID (_rdt_uuid) and Reddit click ID
     * (_rdt_cid), plus the AxiTrace visitor/session cookies.
     *
     * Without these the purchase reaches TikTok Events API and Reddit CAPI carrying no
     * platform identifier of its own, and Meta/TikTok/Reddit get no external_id — measured
     * 2026-08-18 across 893 live orders: external_id 0%. The browser events on the very
     * same visit carry all of them, so the data exists; it was simply never captured at
     * the one point where the purchase can still see the customer's cookies.
     */
    public const CUSTOM_FIELD_TTP = 'axitrace_ttp';
    public const CUSTOM_FIELD_RDT_UUID = 'axitrace_rdt_uuid';
    public const CUSTOM_FIELD_RDT_CID = 'axitrace_rdt_cid';
    public const CUSTOM_FIELD_VISITOR_ID = 'axitrace_visitor_id';
    public const CUSTOM_FIELD_SESSION_ID = 'axitrace_session_id';

    /**
     * Google Analytics client cookie format: GA1.2.<random>.<first_visit_ts>
     */
    private const GA_PATTERN = '/^GA\d+\.\d+\.\d+\.\d+$/';

    /**
     * Google Analytics 4 per-property session cookie value (GS1./GS2. prefixed).
     */
    private const GA_SESSION_PATTERN = '/^GS\d+\./';

    /** Upper bound for forwarded User-Agent strings (defensive cap, not a spec limit). */
    private const MAX_UA_LENGTH = 512;

    /** Upper bound for forwarded GA session cookie values. */
    private const MAX_GA_SESSION_LENGTH = 256;

    /**
     * TikTok browser ID cookie, e.g. "01KCFX1BV9NB74R5ZE592YDTYN_.tt.1".
     */
    private const TTP_PATTERN = '/^[A-Za-z0-9_.-]{8,128}$/';

    /**
     * Reddit browser ID cookie (_rdt_uuid), optionally prefixed with a creation
     * timestamp: "1787027570150.35164282-961b-4313-9900-7212ba542074".
     * Mirrors UserIdentityService::isValidRdtUuid() in event-worker.
     */
    private const RDT_UUID_PATTERN = '/^(\d{10,16}\.)?[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    /**
     * Reddit click ID cookie written by the AxiTrace web SDK in its versioned
     * format "v2|<firstSeenMs>|<clickId>".
     */
    private const RDT_CID_PATTERN = '/^v\d+\|\d{10,16}\|[A-Za-z0-9_.-]{8,500}$/';

    /**
     * AxiTrace visitor/session cookies (vt_vid, vt_sid) — UUIDs written by the web SDK.
     */
    private const AXITRACE_ID_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    /**
     * Facebook Browser ID cookie format: fb.1.<timestamp>.<random_digits>
     * Mirrors UserIdentityService::isValidFbp() in event-worker.
     */
    private const FBP_PATTERN = '/^fb\.\d+\.\d+\.\d+$/';

    /**
     * Facebook Click ID cookie format: fb.1.<timestamp>.<fbclid>
     * Mirrors UserIdentityService::isValidFbc() in event-worker.
     */
    private const FBC_PATTERN = '/^fb\.\d+\.\d+\.[A-Za-z0-9_-]+$/';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutOrderPlacedEvent::class => 'onOrderPlaced'];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        try {
            $this->capture($event);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'AxiTrace: order-placed click-id capture failed: ' . $e::class . ': ' . $e->getMessage()
            );
        }
    }

    private function capture(CheckoutOrderPlacedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $customFields = [];

        $fbp = $this->validate((string) $request->cookies->get('_fbp', ''), self::FBP_PATTERN);
        if ($fbp !== null) {
            $customFields[self::CUSTOM_FIELD_FBP] = $fbp;
        }

        $fbc = $this->validate((string) $request->cookies->get('_fbc', ''), self::FBC_PATTERN);
        if ($fbc !== null) {
            $customFields[self::CUSTOM_FIELD_FBC] = $fbc;
        }

        // Real buyer IP + User-Agent. The purchase event itself is dispatched later
        // (order_transaction "paid" transition) from a server context where the
        // ingestion API would only ever see the shop server's IP and HTTP client UA —
        // which degrades Facebook CAPI match quality. Capture the genuine values here.
        $clientIp = (string) ($request->getClientIp() ?? '');
        if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP) !== false) {
            $customFields[self::CUSTOM_FIELD_CLIENT_IP] = $clientIp;
        }

        $userAgent = trim((string) $request->headers->get('User-Agent', ''));
        if ($userAgent !== '') {
            $customFields[self::CUSTOM_FIELD_CLIENT_UA] = mb_substr($userAgent, 0, self::MAX_UA_LENGTH);
        }

        // GA cookies — let the server-side purchase reach GA4 with the buyer's real
        // client_id/session so it stitches to their on-site session instead of
        // appearing as an unattributed new user.
        $ga = $this->validate((string) $request->cookies->get('_ga', ''), self::GA_PATTERN);
        if ($ga !== null) {
            $customFields[self::CUSTOM_FIELD_GA] = $ga;
        }

        // TikTok / Reddit browser and click IDs, and the AxiTrace visitor/session IDs.
        // Same reasoning as the Meta cookies above: the paid transition cannot see them.
        foreach ([
            self::CUSTOM_FIELD_TTP => ['_ttp', self::TTP_PATTERN],
            self::CUSTOM_FIELD_RDT_UUID => ['_rdt_uuid', self::RDT_UUID_PATTERN],
            self::CUSTOM_FIELD_RDT_CID => ['_rdt_cid', self::RDT_CID_PATTERN],
            self::CUSTOM_FIELD_VISITOR_ID => ['vt_vid', self::AXITRACE_ID_PATTERN],
            self::CUSTOM_FIELD_SESSION_ID => ['vt_sid', self::AXITRACE_ID_PATTERN],
        ] as $customField => [$cookieName, $pattern]) {
            $value = $this->validate((string) $request->cookies->get($cookieName, ''), $pattern);
            if ($value !== null) {
                $customFields[$customField] = $value;
            }
        }

        $gaSession = $this->findGaSessionCookie($request->cookies->all());
        if ($gaSession !== null) {
            $customFields[self::CUSTOM_FIELD_GA_SESSION] = $gaSession;
        }

        if ($customFields === []) {
            return;
        }

        $this->orderRepository->update([[
            'id'           => $event->getOrder()->getId(),
            'customFields' => $customFields,
        ]], $event->getContext());
    }

    /**
     * Finds the first GA4 per-property session cookie (_ga_<CONTAINER-ID>) and
     * returns its value, or null when none is present/valid.
     *
     * @param array<string, mixed> $cookies
     */
    private function findGaSessionCookie(array $cookies): ?string
    {
        foreach ($cookies as $name => $value) {
            if (!is_string($name) || !is_string($value) || !str_starts_with($name, '_ga_')) {
                continue;
            }
            if (preg_match(self::GA_SESSION_PATTERN, $value) === 1) {
                return mb_substr($value, 0, self::MAX_GA_SESSION_LENGTH);
            }
        }

        return null;
    }

    /**
     * Returns the cookie value only if it matches the given format, null otherwise.
     * Never forwards a malformed or empty value.
     */
    private function validate(string $value, string $pattern): ?string
    {
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return $value;
    }
}
