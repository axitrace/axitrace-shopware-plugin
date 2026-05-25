<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Builds the GeneratedEvent-shaped payload that ingestion-api expects.
 *
 * Output shape mirrors MagentoEventNormalizer and WooCommerce normalizer:
 *   {
 *     event, eventSalt, event_id, transactionId, orderId,
 *     workspace_public_key, source: "shopware", timestamp, ip, userAgent,
 *     pluginVersion, sdkVersion,
 *     billingCity, billingCountry, billingZip,
 *     data: {
 *       client: { email, phone },
 *       products: [{ productId, sku, name, quantity, price, currency }],
 *       revenue: { amount, currency },
 *       value: { amount, currency }
 *     }
 *   }
 *
 * IMPORTANT: Both `revenue` and `value` are ALWAYS the object shape
 * { "amount": float, "currency": string } — never a bare float.
 * The event-worker prepareTransaction (v0.1.2+) handles both shapes,
 * but we standardize on the object shape going forward.
 *
 * PII is forwarded in plain text — the Facebook CAPI PHP SDK and TikTok
 * Events API auto-hash email/phone. Only `external_id` needs manual SHA-256,
 * and it is not included in v0.1.0.
 *
 * Currency: read via $order->getCurrency()?->getIsoCode() (presentation
 * currency, not base currency) — matches the lesson from AstrophotoMarket.
 *
 * Required associations to load before calling normalize():
 *   currency, billingAddress, billingAddress.country, orderCustomer, lineItems
 *
 * This is a pure mapper — no constructor dependencies, no side effects.
 */
final class OrderEventNormalizer
{
    private const PLUGIN_VERSION = '0.1.0';
    private const SDK_VERSION    = 'shopware-1.0';
    private const SOURCE         = 'shopware';

    /**
     * Converts an OrderEntity (with pre-loaded associations) into the
     * GeneratedEvent payload array expected by AxiTrace ingestion-api.
     *
     * @param OrderEntity $order             Shopware order with loaded associations.
     * @param string      $eventId           Deterministic UUID v5 for deduplication.
     * @param string      $workspacePublicKey AxiTrace workspace public key.
     *
     * @return array<string, mixed>
     */
    public function normalize(OrderEntity $order, string $eventId, string $workspacePublicKey): array
    {
        $orderCurrency  = $order->getCurrency()?->getIsoCode() ?? '';
        $billing        = $order->getBillingAddress();
        $orderCustomer  = $order->getOrderCustomer();
        $lineItems      = $order->getLineItems();
        $revenueAmount  = (float) $order->getAmountTotal();

        $products = [];
        if ($lineItems !== null) {
            foreach ($lineItems as $item) {
                if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                    continue;
                }

                $products[] = [
                    'productId' => (string) ($item->getProductId() ?? $item->getId()),
                    'sku'       => (string) ($item->getPayload()['productNumber'] ?? ''),
                    'name'      => (string) $item->getLabel(),
                    'quantity'  => (float) $item->getQuantity(),
                    'price'     => (float) $item->getUnitPrice(),
                    'currency'  => $orderCurrency,
                ];
            }
        }

        $money = [
            'amount'   => $revenueAmount,
            'currency' => $orderCurrency,
        ];

        return [
            'event'                 => 'transaction.charge',
            'eventSalt'             => $eventId,
            'event_id'              => $eventId,
            'transactionId'         => $eventId,
            'orderId'               => (string) $order->getId(),
            'workspace_public_key'  => $workspacePublicKey,
            'source'                => self::SOURCE,
            'timestamp'             => gmdate('Y-m-d\TH:i:s\Z'),
            // Shopware does not expose the remote IP on OrderEntity post-payment;
            // the ingestion-api falls back to the real client IP from the request.
            'ip'                    => '',
            'userAgent'             => '',
            'pluginVersion'         => self::PLUGIN_VERSION,
            'sdkVersion'            => self::SDK_VERSION,
            'billingCity'           => $billing !== null ? (string) $billing->getCity() : '',
            'billingCountry'        => $billing?->getCountry()?->getIso() ?? '',
            'billingZip'            => $billing !== null ? (string) $billing->getZipcode() : '',
            'data' => [
                'client' => [
                    'email' => $orderCustomer !== null ? (string) $orderCustomer->getEmail() : '',
                    'phone' => $billing !== null ? (string) $billing->getPhoneNumber() : '',
                ],
                'products' => $products,
                'revenue'  => $money,
                'value'    => $money,
            ],
        ];
    }
}
