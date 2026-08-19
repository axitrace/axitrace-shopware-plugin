<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Subscriber;

use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use AxitraceShopware6\Subscriber\OrderPaidSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact event names the plugin subscribes to.
 *
 * Why this exists: `SomeClass::class` on a class that does NOT exist still
 * compiles to its string silently. Through 0.1.8 the order-placed subscriber
 * was registered for `Shopware\Core\Checkout\Cart\Order\CheckoutOrderPlacedEvent`
 * (wrong namespace, class never existed) — the subscription registered fine
 * and simply never fired, so buyer IP/UA/cookies were never captured on any
 * order. These assertions are plain string comparisons, so they run and fail
 * loudly even WITHOUT Shopware installed; when shopware/core is available the
 * class_exists checks additionally prove the classes are real.
 */
final class OrderPlacedSubscriberEventContractTest extends TestCase
{
    private const ORDER_PLACED_EVENT = 'Shopware\\Core\\Checkout\\Cart\\Event\\CheckoutOrderPlacedEvent';

    public function testOrderPlacedSubscriberListensToTheCanonicalEventClass(): void
    {
        self::assertSame(
            [self::ORDER_PLACED_EVENT => 'onOrderPlaced'],
            OrderPlacedSubscriber::getSubscribedEvents(),
        );

        if (class_exists('Shopware\\Core\\Framework\\Uuid\\Uuid')) {
            // Shopware is installed — the event class itself must exist too.
            self::assertTrue(
                class_exists(self::ORDER_PLACED_EVENT),
                'Subscribed order-placed event class does not exist in this Shopware version.',
            );
        }
    }

    public function testOrderPaidSubscriberListensToThePaidStateTransition(): void
    {
        self::assertSame(
            ['state_enter.order_transaction.state.paid' => 'onOrderPaid'],
            OrderPaidSubscriber::getSubscribedEvents(),
        );
    }
}
