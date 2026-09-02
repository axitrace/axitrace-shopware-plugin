<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
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
 *       value: { amount, currency },
 *       orderNumber: string,          // human-readable order number (GA4 transaction_id)
 *       tax: float, shipping: float,  // gross VAT / shipping contained in the order
 *       valueBasis: string,           // which amount `value`/`revenue` report (ConversionValueBasis)
 *       fbp?: string, fbc?: string,   // present only when captured at order placement
 *       _ga?: string, ga_session_id?: string  // GA cookies captured at order placement
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
 * fbp/fbc: read from $order->getCustomFields() (a base scalar field, always
 * hydrated — no addAssociation() needed), written by OrderPlacedSubscriber at
 * order-placement time.
 *
 * `revenue`/`value` report the amount selected by the merchant's "conversion value"
 * setting ({@see ConversionValueBasis}); the default is the historical order total
 * including VAT and shipping. Products keep their unit prices as charged.
 *
 * This is a pure mapper — no I/O, no side effects.
 */
final class OrderEventNormalizer
{
    private const PLUGIN_VERSION = '0.2.0';
    private const SDK_VERSION    = 'shopware-1.0';
    private const SOURCE         = 'shopware';

    private readonly ConversionValueResolver $valueResolver;

    public function __construct(?ConversionValueResolver $valueResolver = null)
    {
        // Optional so the class stays constructible with `new OrderEventNormalizer()`
        // (services.xml, tests) — the resolver is a pure, stateless helper.
        $this->valueResolver = $valueResolver ?? new ConversionValueResolver();
    }

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
    public function normalize(
        OrderEntity $order,
        string $eventId,
        string $workspacePublicKey,
        ConversionValueBasis $valueBasis = ConversionValueBasis::GrossTotal,
    ): array {
        $orderCurrency  = $order->getCurrency()?->getIsoCode() ?? '';
        $billing        = $order->getBillingAddress();
        $orderCustomer  = $order->getOrderCustomer();
        $lineItems      = $order->getLineItems();

        // Order amounts. Shopware always exposes the gross and net grand totals;
        // the shipping breakdown lives on the CalculatedPrice, which is null on
        // some programmatically created orders — treat that as free shipping.
        $amountTotal   = (float) $order->getAmountTotal();
        $amountNet     = (float) $order->getAmountNet();
        $shippingCosts = $order->getShippingCosts();
        $shippingGross = $shippingCosts !== null ? (float) $shippingCosts->getTotalPrice() : 0.0;
        $shippingTax   = $shippingCosts !== null ? (float) $shippingCosts->getCalculatedTaxes()->getAmount() : 0.0;
        $revenueAmount = $this->valueResolver->resolve($valueBasis, $amountTotal, $amountNet, $shippingGross, $shippingTax);

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

        // firstName/lastName are match keys Meta hashes into fn/ln and TikTok into
        // first_name/last_name. Shopware has always had them on the billing address; they
        // were simply never forwarded, so every order reached Meta with fn/ln null.
        $data = [
            'client' => [
                'email' => $orderCustomer !== null ? (string) $orderCustomer->getEmail() : '',
                'phone' => $billing !== null ? (string) $billing->getPhoneNumber() : '',
                'firstName' => $billing !== null ? (string) $billing->getFirstName() : '',
                'lastName' => $billing !== null ? (string) $billing->getLastName() : '',
            ],
            'products' => $products,
            'revenue'  => $money,
            'value'    => $money,
        ];

        // Captured at order placement by OrderPlacedSubscriber (request-scoped — the
        // "paid" transition that triggers this normalizer runs asynchronously for many
        // payment methods and has no cookie access). Omitted entirely when absent so
        // the payload stays minimal for stores without the corresponding cookies.
        $customFields = $order->getCustomFields() ?? [];
        $fbp = (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_FBP] ?? '');
        $fbc = (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_FBC] ?? '');

        if ($fbp !== '') {
            $data['fbp'] = $fbp;
        }
        if ($fbc !== '') {
            $data['fbc'] = $fbc;
        }

        // TikTok / Reddit identifiers captured at order placement. Without them the
        // purchase reaches TikTok Events API and Reddit CAPI with no platform identifier
        // of its own, leaving those destinations to match on e-mail alone.
        foreach ([
            'ttp' => OrderPlacedSubscriber::CUSTOM_FIELD_TTP,
            'rdt_uuid' => OrderPlacedSubscriber::CUSTOM_FIELD_RDT_UUID,
            'rdt_cid' => OrderPlacedSubscriber::CUSTOM_FIELD_RDT_CID,
        ] as $key => $customField) {
            $value = (string) ($customFields[$customField] ?? '');
            if ($value !== '') {
                $data[$key] = $value;
            }
        }

        // Google Analytics cookies captured at order placement — lets the server-side
        // GA4 Measurement Protocol purchase carry the buyer's real client_id/session
        // so GA4 stitches it to their on-site session instead of a generated id.
        $ga = (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_GA] ?? '');
        $gaSession = (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_GA_SESSION] ?? '');

        if ($ga !== '') {
            $data['_ga'] = $ga;
        }
        if ($gaSession !== '') {
            $data['ga_session_id'] = $gaSession;
        }

        // The shopper's consent decision recorded at order placement ('granted' or
        // 'denied'). AxiTrace's workspace consent policy reads it from `data.consent`
        // to decide whether this purchase may be forwarded to the ad platforms.
        // Omitted when the order carries no decision (placed before 0.2.0, or
        // created in the admin / via API / by an import) so the worker sees
        // "no consent state" rather than a guessed one.
        $consent = $customFields[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null;
        if ($consent === ConsentGate::DECISION_GRANTED || $consent === ConsentGate::DECISION_DENIED) {
            $data['consent'] = $consent;
        }

        // Human-readable order number (e.g. "10042") — becomes the GA4 transaction_id
        // and the ClickHouse order_id so merchants can reconcile against their shop admin.
        $data['orderNumber'] = (string) ($order->getOrderNumber() ?? '');

        // VAT and shipping contained in the order, always gross and independent of the
        // configured value basis — GA4 reports them as the purchase `tax`/`shipping`
        // params, and they let the merchant reconstruct any other basis downstream.
        $data['tax']      = max(0.0, round($amountTotal - $amountNet, 2));
        $data['shipping'] = max(0.0, round($shippingGross, 2));
        $data['valueBasis'] = $valueBasis->value;

        return [
            'event'                 => 'transaction.charge',
            'eventSalt'             => $eventId,
            'event_id'              => $eventId,
            'transactionId'         => $eventId,
            'orderId'               => (string) $order->getId(),
            'workspace_public_key'  => $workspacePublicKey,
            'source'                => self::SOURCE,
            'timestamp'             => gmdate('Y-m-d\TH:i:s\Z'),
            // Real buyer IP/User-Agent captured at order placement by OrderPlacedSubscriber
            // (the "paid" transition runs server-side with no request context). When absent
            // the ingestion-api falls back to the transport request's IP/UA (the shop server),
            // which is the pre-0.1.5 behavior.
            'ip'                    => (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_CLIENT_IP] ?? ''),
            'userAgent'             => (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_CLIENT_UA] ?? ''),
            'pluginVersion'         => self::PLUGIN_VERSION,
            'sdkVersion'            => self::SDK_VERSION,
            // AxiTrace visitor/session cookies captured at order placement. They become
            // the external_id every destination matches on and stitch this server-side
            // purchase to the buyer's browser profile; without them external_id was 0%.
            'userId'                => (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_VISITOR_ID] ?? ''),
            'sessionId'             => (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_SESSION_ID] ?? ''),
            'billingCity'           => $billing !== null ? (string) $billing->getCity() : '',
            'billingCountry'        => $billing?->getCountry()?->getIso() ?? '',
            'billingZip'            => $billing !== null ? (string) $billing->getZipcode() : '',
            // State/province — Meta `st`, TikTok `state`.
            'billingState'          => $this->normalizeStateCode($billing?->getCountryState()),
            'data'                  => $data,
        ];
    }

    /**
     * Subdivision code for the buyer's state/province.
     *
     * Shopware stores ISO 3166-2 short codes ("DE-BW", "US-CA"), but Meta expects the
     * bare subdivision ("bw", "ca") — its normalizer strips punctuation, so an unstripped
     * "DE-BW" would hash as "debw" and never match. The country prefix is therefore
     * removed here; the full state name is the fallback when no code exists.
     */
    private function normalizeStateCode(?CountryStateEntity $state): string
    {
        if ($state === null) {
            return '';
        }

        $shortCode = trim((string) $state->getShortCode());

        if ($shortCode !== '') {
            // "DE-BW" -> "BW"; a bare "BW" is left untouched.
            if (preg_match('/^[A-Za-z]{2}-(.+)$/', $shortCode, $matches) === 1) {
                return $matches[1];
            }

            return $shortCode;
        }

        return trim((string) $state->getName());
    }
}
