<?php

declare(strict_types=1);

namespace AxitraceShopware6\EventId;

use Symfony\Component\Uid\Uuid;

/**
 * Deterministic UUID v5 generator for AxiTrace Shopware 6 order events.
 *
 * MUST stay byte-identical to the Go ingestion-api equivalent in
 * ingestion-api/shopware_pixel.go so that client-side and server-side
 * event IDs deduplicate correctly at the ad-platform layer (Facebook CAPI,
 * TikTok Events API, Google Ads, and GA4 all dedupe by event_id).
 *
 * Algorithm: RFC 4122 §4.3 — SHA-1 of (namespace bytes || input), with the
 * version nibble forced to 5 and the variant nibble forced to 10xxxxxx.
 * Internal hashing is delegated to symfony/uid (Uuid::v5()) — no raw SHA-1
 * in this file.
 *
 * Go counterpart composite-key format:
 *   key := "shopware_order:" + orderId + ":tx" + transactionId
 *   eventID = uuid.NewSHA1(shopwareOrderNamespace, []byte(key)).String()
 * where shopwareOrderNamespace = "5e5e5e5e-5e5e-5e5e-5e5e-5e5e5e5e5e5e".
 *
 * Cross-language parity test vector:
 *   orderId      = "019503e4-1234-7abc-8def-0123456789ab"
 *   transactionId = "TRANS-UUID-HERE"
 *   expected     = "a86a3492-94e6-585c-bea8-fe689277774a"
 * See tests/fixtures/uuid_parity_vector.txt.
 */
final class UuidV5Generator
{
    /**
     * Shared namespace UUID across all AxiTrace platform integrations.
     *
     * The differentiating element is the ORDER_INPUT_PREFIX constant, which
     * isolates Shopware IDs from Shopify (`shopify_order:`), WooCommerce
     * (`woocommerce_order:`), and Magento (`magento_order:`) regardless of
     * any UUID overlap between platforms.
     */
    private const NAMESPACE_UUID = '5e5e5e5e-5e5e-5e5e-5e5e-5e5e5e5e5e5e';

    private const ORDER_INPUT_PREFIX = 'shopware_order:';

    /**
     * Returns a deterministic UUID v5 for the given Shopware order + transaction pair.
     *
     * The composite key fed into the hash is:
     *   "shopware_order:{$orderId}:tx{$transactionId}"
     *
     * This matches the Go ingestion-api (shopware_pixel.go) byte-for-byte.
     *
     * @throws \InvalidArgumentException if $orderId or $transactionId is empty.
     */
    public function forOrder(string $orderId, string $transactionId): string
    {
        if ($orderId === '') {
            throw new \InvalidArgumentException('UuidV5Generator: orderId must be non-empty.');
        }

        if ($transactionId === '') {
            throw new \InvalidArgumentException('UuidV5Generator: transactionId must be non-empty.');
        }

        $key = self::ORDER_INPUT_PREFIX . $orderId . ':tx' . $transactionId;

        return Uuid::v5(Uuid::fromString(self::NAMESPACE_UUID), $key)->toRfc4122();
    }
}
