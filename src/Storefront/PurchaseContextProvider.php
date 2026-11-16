<?php

declare(strict_types=1);

namespace AxitraceShopware6\Storefront;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\Exclusion\CustomerGroupExclusion;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use AxitraceShopware6\Order\PaidTransactionResolver;
use AxitraceShopware6\Subscriber\OrderPlacedSubscriber;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * The browser-pixel Purchase of the order confirmation page.
 *
 * The purchase itself reaches AxiTrace server-side only, when the order's
 * transaction enters "paid" (OrderPaidSubscriber). This context lets the SDK
 * fire the matching Purchase / CompletePayment on the merchant's own Meta and
 * TikTok pixels with the SAME event id, so the platforms count the order once
 * and still get the browser signal of Meta's recommended redundant setup.
 *
 * It exists only when the server-side purchase was (or is being) sent for this
 * order, judged by the same rules: the paid transaction the event id is keyed
 * on, the excluded customer groups, and the server-side consent decision. An
 * order still awaiting payment gets no context: its server-side purchase may
 * come days later, outside the platforms' deduplication window, or never.
 */
final class PurchaseContextProvider
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly PluginConfig $config,
        private readonly ConsentGate $consentGate,
        private readonly CustomerGroupExclusion $customerGroupExclusion,
        private readonly OrderEventNormalizer $normalizer,
        private readonly PinterestCatalogIdResolver $pinterestIdResolver,
        private readonly PaidTransactionResolver $paidTransactionResolver,
        private readonly UuidV5Generator $uuidGenerator,
    ) {
    }

    /**
     * @return array{eventId: string, orderNumber: string, value: float, currency: string, quantity: int, items: list<array<string, mixed>>}|null
     */
    public function forOrder(string $orderId, string $salesChannelId, Context $context): ?array
    {
        if ($orderId === '') {
            return null;
        }

        $order = $this->loadOrder($orderId, $context);
        if ($order === null || $order->getSalesChannelId() !== $salesChannelId) {
            return null;
        }

        if ($this->customerGroupExclusion->excludesOrder($order)) {
            return null;
        }

        $decision = $order->getCustomFields()[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null;
        if (!$this->consentGate->allowsServerTracking(
            $this->config->getConsentMode($salesChannelId),
            is_string($decision) ? $decision : null,
        )) {
            return null;
        }

        $transactionId = $this->paidTransactionResolver->paidTransactionId($order);
        if ($transactionId === null) {
            return null;
        }

        $currency = (string) ($order->getCurrency()?->getIsoCode() ?? '');
        $pinterestMode = $this->config->getPinterestCatalogIdMode($salesChannelId);

        $items = [];
        $quantity = 0;
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            // The same product reference the server-side purchase carries, so the
            // browser and server Purchase name the same catalog items.
            $productId = (string) ($lineItem->getProductId() ?? $lineItem->getId());
            $payload = $lineItem->getPayload();
            $productNumber = (string) ($payload['productNumber'] ?? '');
            $item = [
                'productId' => $productId,
                'sku' => $productNumber,
                'name' => (string) $lineItem->getLabel(),
                'quantity' => $lineItem->getQuantity(),
                'price' => (float) $lineItem->getUnitPrice(),
                'currency' => $currency,
            ];
            $pinterestId = $this->pinterestIdResolver->resolve($pinterestMode, $productNumber, $productId);
            if ($pinterestId !== null) {
                $item['pinterest_id'] = $pinterestId;
            }
            $items[] = $item;
            $quantity += $lineItem->getQuantity();
        }

        return [
            'eventId' => $this->uuidGenerator->forOrder($orderId, $transactionId),
            'orderNumber' => (string) $order->getOrderNumber(),
            'value' => round($this->normalizer->conversionValue($order, $this->config->getConversionValueBasis($salesChannelId)), 2),
            'currency' => $currency,
            'quantity' => $quantity,
            'items' => $items,
        ];
    }

    /**
     * Re-read with exactly what the decision needs: the finish page's own order
     * comes without the transactions' state, and the gates read the customer.
     */
    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('transactions.stateMachineState');

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }
}
