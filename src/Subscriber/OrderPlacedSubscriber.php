<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Order\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Captures the merchant's own Meta browser pixel cookies (_fbp/_fbc) at order
 * placement time and persists them onto the order's customFields.
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

        $fbp = $this->validate((string) $request->cookies->get('_fbp', ''), self::FBP_PATTERN);
        $fbc = $this->validate((string) $request->cookies->get('_fbc', ''), self::FBC_PATTERN);

        if ($fbp === null && $fbc === null) {
            return;
        }

        $customFields = [];
        if ($fbp !== null) {
            $customFields[self::CUSTOM_FIELD_FBP] = $fbp;
        }
        if ($fbc !== null) {
            $customFields[self::CUSTOM_FIELD_FBC] = $fbc;
        }

        $this->orderRepository->update([[
            'id'           => $event->getOrder()->getId(),
            'customFields' => $customFields,
        ]], $event->getContext());
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
