<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * The identifiers of one order as AxiTrace sees them - the single place both
 * the purchase (OrderEventNormalizer) and the refund (RefundEventNormalizer)
 * read them from, so the two can never drift apart again.
 *
 * AxiTrace stores a `/shopware/pixel` purchase under `data.orderNumber` when it
 * is non-empty, otherwise under the top-level `orderId` (the Shopware order
 * UUID). `POST /v1/refund` is matched to the purchase by its `orderId`, so a
 * refund must carry exactly {@see self::storedId()}: in 0.5.0 before this
 * helper existed it sent the UUID and no refund ever matched its purchase.
 *
 * Pure: no I/O.
 */
final class OrderReference
{
    /** The Shopware order UUID, sent as the purchase's top-level `orderId`. */
    public static function id(OrderEntity $order): string
    {
        return (string) $order->getId();
    }

    /** The human-readable order number, sent as the purchase's `data.orderNumber`. */
    public static function number(OrderEntity $order): string
    {
        return (string) ($order->getOrderNumber() ?? '');
    }

    /** The identifier AxiTrace stores the purchase under: the order number, else the UUID. */
    public static function storedId(OrderEntity $order): string
    {
        $number = self::number($order);

        return $number !== '' ? $number : self::id($order);
    }
}
