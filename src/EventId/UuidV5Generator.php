<?php

declare(strict_types=1);

namespace AxitraceShopware6\EventId;

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
 *
 * Self-contained on purpose: Shopware does not declare symfony/uid as a
 * required package, and adding it to composer.json `require` fails the
 * Shopware Plugin Requirements Validator at install time. The raw SHA-1
 * implementation here is the same one used by the AxiTrace Magento plugin
 * (`magento-plugin/Model/EventId/UuidV5Generator.php`) and has cross-language
 * parity tests against the Go counterpart.
 *
 * Go counterpart composite-key format:
 *   key := "shopware_order:" + orderId + ":tx" + transactionId
 *   eventID = uuid.NewSHA1(shopwareOrderNamespace, []byte(key)).String()
 * where shopwareOrderNamespace = "5e5e5e5e-5e5e-5e5e-5e5e-5e5e5e5e5e5e".
 *
 * Cross-language parity test vector:
 *   orderId       = "019503e4-1234-7abc-8def-0123456789ab"
 *   transactionId = "TRANS-UUID-HERE"
 *   expected      = "a86a3492-94e6-585c-bea8-fe689277774a"
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

        return self::uuidV5(self::NAMESPACE_UUID, $key);
    }

    private static function uuidV5(string $namespaceUuid, string $name): string
    {
        $nhex = str_replace(['-', '{', '}'], '', $namespaceUuid);
        if (strlen($nhex) !== 32 || !ctype_xdigit($nhex)) {
            throw new \InvalidArgumentException('UuidV5Generator: invalid namespace UUID.');
        }

        // Convert namespace to its 16-byte binary form.
        $nbin = '';
        for ($i = 0; $i < 32; $i += 2) {
            $nbin .= chr((int) hexdec($nhex[$i] . $nhex[$i + 1]));
        }

        $hash = sha1($nbin . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            // Version 5 — top nibble of time_hi_and_version forced to 0101.
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // Variant RFC 4122 — top two bits of clock_seq_hi forced to 10.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }
}
