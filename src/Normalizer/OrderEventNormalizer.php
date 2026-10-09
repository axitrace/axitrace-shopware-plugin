<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use AxitraceShopware6\ClickId\PersistedClickIdReader;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Config\PinterestCatalogIdMode;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
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
 *       products: [{ productId, externalId, sku, name, quantity, price, currency,
 *                    unitCost?: { amount, currency } }],
 *       revenue: { amount, currency },
 *       value: { amount, currency },
 *       orderNumber: string,          // human-readable order number (GA4 transaction_id)
 *       tax: float, shipping: float,  // gross VAT / shipping contained in the order
 *       taxesIncluded?: bool,         // the order's tax status: true for gross, false for net / tax-free
 *       valueBasis: string,           // which amount `value`/`revenue` report (ConversionValueBasis)
 *       paymentInfo?: { method },     // payment method technical name (payment fee rules)
 *       fbp?: string, fbc?: string,   // present only when captured at order placement
 *       ttp?, rdt_uuid?, obref?: string,          // pixel browser ids, same
 *       gclid?, gbraid?, wbraid?, ttclid?, rdt_cid?, oppref?: string,  // bare ad click ids, same
 *       _ga?: string, ga_session_id?: string  // GA cookies captured at order placement
 *     }
 *   }
 *
 * IMPORTANT: Both `revenue` and `value` are ALWAYS the object shape
 * { "amount": float, "currency": string } - never a bare float.
 * The event-worker prepareTransaction (v0.1.2+) handles both shapes,
 * but we standardize on the object shape going forward.
 *
 * PII is forwarded in plain text - the Facebook CAPI PHP SDK and TikTok
 * Events API auto-hash email/phone. Only `external_id` needs manual SHA-256,
 * and it is not included in v0.1.0.
 *
 * Currency: read via $order->getCurrency()?->getIsoCode() (presentation
 * currency, not base currency) - matches the lesson from AstrophotoMarket.
 *
 * Required associations to load before calling normalize():
 *   currency, billingAddress, billingAddress.country, orderCustomer, lineItems
 *
 * fbp/fbc: read from $order->getCustomFields() (a base scalar field, always
 * hydrated - no addAssociation() needed), written by OrderPlacedSubscriber at
 * order-placement time.
 *
 * `revenue`/`value` report the amount selected by the merchant's "conversion value"
 * setting ({@see ConversionValueBasis}); the default is the historical order total
 * including VAT and shipping. Products keep their unit prices as charged.
 *
 * Cost data (0.5.0): `unitCost` is the NET purchase price per unit
 * ({@see PurchaseCostResolver}) and is added ONLY when the caller passes
 * `$includeCosts = true`, which OrderPaidSubscriber does only when the
 * merchant configured the AxiTrace secret key - the server keeps cost fields
 * only on secret-key authenticated requests. `externalId`
 * (`shopware:<product id>`) is a plain product reference and is always sent.
 *
 * This is a pure mapper - no I/O, no side effects.
 */
final class OrderEventNormalizer
{
    private const PLUGIN_VERSION = '0.5.3';
    private const EXTERNAL_ID_PREFIX = 'shopware:';
    private const SDK_VERSION    = 'shopware-1.0';
    private const SOURCE         = 'shopware';

    private readonly ConversionValueResolver $valueResolver;
    private readonly PinterestCatalogIdResolver $pinterestIdResolver;
    private readonly PurchaseCostResolver $costResolver;

    public function __construct(
        ?ConversionValueResolver $valueResolver = null,
        ?PinterestCatalogIdResolver $pinterestIdResolver = null,
        ?PurchaseCostResolver $costResolver = null,
    ) {
        // Optional so the class stays constructible with `new OrderEventNormalizer()`
        // (services.xml, tests) - the resolver is a pure, stateless helper.
        $this->valueResolver = $valueResolver ?? new ConversionValueResolver();
        $this->pinterestIdResolver = $pinterestIdResolver ?? new PinterestCatalogIdResolver();
        $this->costResolver = $costResolver ?? new PurchaseCostResolver();
    }

    /**
     * The product reference AxiTrace matches cost catalog entries and refund
     * lines on. Empty for a line without a product id.
     */
    public static function externalIdFor(?string $productId): string
    {
        return $productId !== null && $productId !== '' ? self::EXTERNAL_ID_PREFIX . $productId : '';
    }

    /**
     * Converts an OrderEntity (with pre-loaded associations) into the
     * GeneratedEvent payload array expected by AxiTrace ingestion-api.
     *
     * @param OrderEntity $order             Shopware order with loaded associations.
     * @param string      $eventId           Deterministic UUID v5 for deduplication.
     * @param string      $workspacePublicKey AxiTrace workspace public key.
     * @param bool        $includeCosts      Add per-line `unitCost`; true only when the request
     *                                       is sent with the secret key.
     *
     * @return array<string, mixed>
     */
    public function normalize(
        OrderEntity $order,
        string $eventId,
        string $workspacePublicKey,
        ConversionValueBasis $valueBasis = ConversionValueBasis::GrossTotal,
        PinterestCatalogIdMode $pinterestCatalogIdMode = PinterestCatalogIdMode::Legacy,
        bool $includeCosts = false,
    ): array {
        $orderCurrency  = $order->getCurrency()?->getIsoCode() ?? '';
        $billing        = $order->getBillingAddress();
        $orderCustomer  = $order->getOrderCustomer();
        $lineItems      = $order->getLineItems();

        // Order amounts. Shopware always exposes the gross and net grand totals;
        // the shipping breakdown lives on the CalculatedPrice, which is null on
        // some programmatically created orders - treat that as free shipping.
        $amountTotal   = (float) $order->getAmountTotal();
        $amountNet     = (float) $order->getAmountNet();
        $shippingCosts = $order->getShippingCosts();
        $shippingTax   = $shippingCosts !== null ? (float) $shippingCosts->getCalculatedTaxes()->getAmount() : 0.0;
        $shippingGross = $shippingCosts !== null ? (float) $shippingCosts->getTotalPrice() : 0.0;
        // On a net-priced order (B2B tax status "net") Shopware's shipping total
        // is NET and the tax comes on top; on gross and tax-free orders the total
        // already is what the buyer paid. Reported shipping is always gross.
        if ($this->taxStatus($order) === CartPrice::TAX_STATE_NET) {
            $shippingGross += $shippingTax;
        }
        $revenueAmount = $this->valueResolver->resolve($valueBasis, $amountTotal, $amountNet, $shippingGross, $shippingTax);

        $products = [];
        if ($lineItems !== null) {
            foreach ($lineItems as $item) {
                if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                    continue;
                }

                $productId = (string) ($item->getProductId() ?? $item->getId());
                $payload = $item->getPayload();
                $productNumber = (string) ($payload['productNumber'] ?? '');
                $product = [
                    'productId' => $productId,
                ];
                $externalId = self::externalIdFor($item->getProductId());
                if ($externalId !== '') {
                    $product['externalId'] = $externalId;
                }
                $product += [
                    'sku'       => $productNumber,
                    'name'      => (string) $item->getLabel(),
                    'quantity'  => (float) $item->getQuantity(),
                    'price'     => (float) $item->getUnitPrice(),
                    'currency'  => $orderCurrency,
                ];
                $variation = $this->formatVariation($payload['options'] ?? null);
                if ($variation !== '') {
                    $product['variant'] = $variation;
                }
                $associatedProduct = $item->getProduct();
                $brand = trim((string) ($associatedProduct?->getManufacturer()?->getTranslation('name') ?? $associatedProduct?->getManufacturer()?->getName() ?? ''));
                $category = trim((string) ($associatedProduct?->getCategories()?->first()?->getTranslation('name') ?? $associatedProduct?->getCategories()?->first()?->getName() ?? ''));
                if ($brand === '' && isset($payload['brand']) && is_string($payload['brand'])) {
                    $brand = trim($payload['brand']);
                }
                if ($category === '' && isset($payload['category']) && is_string($payload['category'])) {
                    $category = trim($payload['category']);
                }
                if ($brand !== '') {
                    $product['brand'] = $brand;
                }
                if ($category !== '') {
                    $product['category'] = $category;
                }
                $pinterestId = $this->pinterestIdResolver->resolve($pinterestCatalogIdMode, $productNumber, $productId);
                if ($pinterestId !== null) {
                    $product['pinterest_id'] = $pinterestId;
                }
                if ($includeCosts) {
                    $unitCost = $this->costResolver->resolveNetUnitCost($item, (string) $order->getCurrencyId());
                    if ($unitCost !== null) {
                        $product['unitCost'] = ['amount' => $unitCost, 'currency' => $orderCurrency];
                    }
                }
                $products[] = $product;
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

        // Captured at order placement by OrderPlacedSubscriber (request-scoped - the
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

        // TikTok / Reddit / OpenAI Ads browser identifiers captured at order placement.
        // Without them the purchase reaches TikTok Events API, Reddit CAPI and OpenAI
        // Ads with no platform identifier of its own, leaving those destinations to
        // match on e-mail alone.
        foreach ([
            'ttp' => OrderPlacedSubscriber::CUSTOM_FIELD_TTP,
            'rdt_uuid' => OrderPlacedSubscriber::CUSTOM_FIELD_RDT_UUID,
            'obref' => OrderPlacedSubscriber::CUSTOM_FIELD_OBREF,
        ] as $key => $customField) {
            $value = $customFields[$customField] ?? null;
            if (is_string($value) && $value !== '') {
                $data[$key] = $value;
            }
        }

        // Ad click ids captured at order placement (gclid, gbraid, wbraid, ttclid,
        // rdt_cid, oppref, msclkid, twclid, epik, li_fat_id, sccid), forwarded as flat
        // `data` keys - the keys event-worker reads them from. Always the bare click
        // id: unwrap() also reduces a raw web SDK
        // cookie value ("v2|<firstSeenMs>|<clickId>") that plugin 0.5.0 and older
        // stored for rdt_cid on orders that are paid only after the update.
        foreach (OrderPlacedSubscriber::CLICK_ID_CUSTOM_FIELDS as $key => $customField) {
            $value = $customFields[$customField] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $value = trim(PersistedClickIdReader::unwrap($value));
            if ($value !== '') {
                $data[$key] = $value;
            }
        }

        // Google Analytics cookies captured at order placement - lets the server-side
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

        // Human-readable order number (e.g. "10042") - becomes the GA4 transaction_id
        // and the ClickHouse order_id so merchants can reconcile against their shop admin.
        $data['orderNumber'] = OrderReference::number($order);

        // VAT and shipping contained in the order, always gross and independent of the
        // configured value basis - GA4 reports them as the purchase `tax`/`shipping`
        // params, and they let the merchant reconstruct any other basis downstream.
        $data['tax']      = max(0.0, round($amountTotal - $amountNet, 2));
        $data['shipping'] = max(0.0, round($shippingGross, 2));
        // Whether the order's prices include VAT, from the order's own tax status:
        // "gross" (B2C) includes it, "net" (B2B) and "tax-free" do not. Omitted when
        // the order carries no tax status, rather than guessed.
        $taxStatus = $this->taxStatus($order);
        if ($taxStatus !== null) {
            $data['taxesIncluded'] = $taxStatus === CartPrice::TAX_STATE_GROSS;
        }
        $data['valueBasis'] = $valueBasis->value;
        // The payment method the order was paid with, by its technical name
        // (e.g. "payment_paypal"): AxiTrace stores it as the order's payment
        // method and selects the merchant's payment fee rule with it. Omitted
        // when the transaction's payment method was not loaded.
        $paymentMethod = $this->paymentMethod($order);
        if ($paymentMethod !== null) {
            $data['paymentInfo'] = ['method' => $paymentMethod];
        }
        $sourceUrl = (string) ($customFields[OrderPlacedSubscriber::CUSTOM_FIELD_SOURCE_URL] ?? '');
        if ($sourceUrl !== '') {
            $data['url'] = $sourceUrl;
        }

        return [
            'event'                 => 'transaction.charge',
            'eventSalt'             => $eventId,
            'event_id'              => $eventId,
            'transactionId'         => $eventId,
            'orderId'               => OrderReference::id($order),
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
            // State/province - Meta `st`, TikTok `state`.
            'billingState'          => $this->normalizeStateCode($billing?->getCountryState()),
            'data'                  => $data,
        ];
    }

    /**
     * Technical name (else name) of the payment method of the transaction that
     * was paid: the most recently created transaction in state `paid`, else the
     * most recently created transaction (Shopware's active one).
     */
    private function paymentMethod(OrderEntity $order): ?string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return null;
        }

        $latest = null;
        $latestPaid = null;
        foreach ($transactions as $transaction) {
            if ($latest === null || $this->isNewer($transaction, $latest)) {
                $latest = $transaction;
            }
            if ($transaction->getStateMachineState()?->getTechnicalName() === 'paid'
                && ($latestPaid === null || $this->isNewer($transaction, $latestPaid))
            ) {
                $latestPaid = $transaction;
            }
        }

        $method = ($latestPaid ?? $latest)?->getPaymentMethod();
        if ($method === null) {
            return null;
        }

        try {
            $technicalName = trim((string) $method->getTechnicalName());
        } catch (\Error) {
            // Typed property left uninitialised on an entity not hydrated by the DAL.
            $technicalName = '';
        }
        if ($technicalName !== '') {
            return $technicalName;
        }

        $name = trim((string) ($method->getTranslation('name') ?? $method->getName() ?? ''));

        return $name !== '' ? $name : null;
    }

    private function isNewer(OrderTransactionEntity $candidate, OrderTransactionEntity $current): bool
    {
        $candidateAt = $candidate->getCreatedAt();
        $currentAt = $current->getCreatedAt();

        return $candidateAt !== null && ($currentAt === null || $candidateAt >= $currentAt);
    }

    private function taxStatus(OrderEntity $order): ?string
    {
        try {
            $status = $order->getTaxStatus() ?? $order->getPrice()->getTaxStatus();
        } catch (\Error) {
            // getPrice() is a typed non-nullable property that is uninitialised on an
            // order loaded without its price - treat as "unknown".
            return null;
        }

        return $status !== '' ? $status : null;
    }

    private function formatVariation(mixed $variation): string
    {
        if (!is_array($variation)) {
            return '';
        }

        $parts = [];
        foreach ($variation as $value) {
            if (is_array($value)) {
                $value = $value['option'] ?? $value['name'] ?? null;
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }

        return implode(' / ', $parts);
    }

    /**
     * Subdivision code for the buyer's state/province.
     *
     * Shopware stores ISO 3166-2 short codes ("DE-BW", "US-CA"), but Meta expects the
     * bare subdivision ("bw", "ca") - its normalizer strips punctuation, so an unstripped
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
