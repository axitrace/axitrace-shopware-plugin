<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Builds the `POST /v1/refund` bodies for one order state change.
 *
 * Body (the AxiTrace refund contract):
 *   { orderId, refundId, refundedAt, amount, currency, isCancellation,
 *     lines: [{ sku, externalId, quantity, amount }] }
 *
 * `orderId` is the identifier AxiTrace stores the purchase under - the order
 * number when the order has one, else the order UUID - taken from
 * {@see OrderReference::storedId()}, the same helper the purchase reads its
 * identifiers from, so the server can match the refund to the purchase. Amounts are gross, in the order currency, as the money that
 * actually went back to the shopper.
 *
 * Where the amounts come from, in this order of trust:
 *  1. Completed refunds recorded by a payment integration
 *     (`order_transaction_capture_refund`, with positions per order line).
 *     They carry their own id and amount; every trigger re-sends all of them,
 *     the server deduplicates on `refundId`.
 *  2. A transaction in state `refunded` without such records: the whole
 *     transaction amount was returned, with every product line.
 *  3. Order cancelled, or a paid transaction cancelled: whatever has not
 *     already been refunded by 1 or 2, sent as a cancellation
 *     (`isCancellation: true`). An order that was never paid has no purchase
 *     in AxiTrace and produces nothing. Both share one `refundId`, so an
 *     order whose payment and order state are both cancelled counts once.
 *
 * A transaction in `refunded_partially` WITHOUT refund records carries no
 * amount anywhere in Shopware, so nothing is sent for it; inventing an amount
 * would distort profit more than leaving it out (the subscriber logs it).
 *
 * `refundId` is deterministic per source, so a repeated transition or a retry
 * can never count twice: `refund:<capture refund id>`,
 * `tx:<transaction id>:refunded`, `order:<order id>:cancelled`.
 *
 * Required associations: currency, lineItems, transactions.stateMachineState,
 * transactions.captures.refunds.stateMachineState,
 * transactions.captures.refunds.positions.
 *
 * Pure: no I/O; the clock is passed in.
 */
final class RefundEventNormalizer
{
    public const TRIGGER_REFUNDED = 'refunded';
    public const TRIGGER_REFUNDED_PARTIALLY = 'refunded_partially';
    public const TRIGGER_CANCELLED = 'cancelled';
    /**
     * A paid transaction moved straight to `cancelled`. Its state no longer
     * shows that it was paid, so the caller (who saw the transition's from
     * state) vouches for it and the paid check is skipped.
     */
    public const TRIGGER_PAYMENT_CANCELLED = 'payment_cancelled';

    /** Transaction states that mean the purchase was sent to AxiTrace (it is sent on "paid"). */
    private const PAID_STATES = ['paid', 'refunded', 'refunded_partially'];
    private const STATE_REFUNDED = 'refunded';
    private const CAPTURE_REFUND_COMPLETED = 'completed';

    /**
     * @return list<array<string, mixed>> zero or more refund bodies
     */
    public function normalize(OrderEntity $order, string $trigger, \DateTimeImmutable $now): array
    {
        // The UUID only keys the deterministic cancellation refundId; the body's
        // orderId is the identifier the purchase is stored under.
        $orderUuid = OrderReference::id($order);
        $orderId   = OrderReference::storedId($order);
        $currency  = (string) ($order->getCurrency()?->getIsoCode() ?? '');
        if ($orderUuid === '' || $orderId === '' || $currency === '') {
            return [];
        }
        if ($trigger !== self::TRIGGER_PAYMENT_CANCELLED && !$this->wasPaid($order)) {
            return [];
        }

        $refunds = [];
        $refundedTotal = 0.0;
        /** @var array<string, int> $refundedQuantities order line id => quantity already refunded */
        $refundedQuantities = [];

        foreach ($this->transactions($order) as $transaction) {
            $captureRefunds = $this->completedCaptureRefunds($transaction);

            foreach ($captureRefunds as $captureRefund) {
                $lines = [];
                foreach ($captureRefund->getPositions() ?? [] as $position) {
                    $lineItemId = $position->getOrderLineItemId();
                    $refundedQuantities[$lineItemId] = ($refundedQuantities[$lineItemId] ?? 0) + $position->getQuantity();
                    $item = $position->getOrderLineItem() ?? $order->getLineItems()?->get($lineItemId);
                    if ($item instanceof OrderLineItemEntity && $this->isProductLine($item)) {
                        $lines[] = $this->line($item, $position->getQuantity(), $position->getAmount()->getTotalPrice());
                    }
                }

                $amount = $captureRefund->getAmount()->getTotalPrice();
                $refundedTotal += $amount;
                $refunds[] = $this->body(
                    $orderId,
                    'refund:' . $captureRefund->getId(),
                    $captureRefund->getCreatedAt() ?? $now,
                    $amount,
                    $currency,
                    false,
                    $lines,
                );
            }

            if ($captureRefunds === [] && $this->stateOf($transaction) === self::STATE_REFUNDED) {
                $amount = $transaction->getAmount()->getTotalPrice();
                $refundedTotal += $amount;
                $lines = [];
                foreach ($this->productLines($order) as $item) {
                    $refundedQuantities[$item->getId()] = $item->getQuantity();
                    $lines[] = $this->line($item, $item->getQuantity(), $item->getTotalPrice());
                }
                $refunds[] = $this->body(
                    $orderId,
                    'tx:' . $transaction->getId() . ':refunded',
                    $now,
                    $amount,
                    $currency,
                    false,
                    $lines,
                );
            }
        }

        if ($trigger === self::TRIGGER_CANCELLED || $trigger === self::TRIGGER_PAYMENT_CANCELLED) {
            $remaining = round((float) $order->getAmountTotal() - $refundedTotal, 2);
            if ($remaining > 0.0) {
                $lines = [];
                foreach ($this->productLines($order) as $item) {
                    $quantity = $item->getQuantity() - ($refundedQuantities[$item->getId()] ?? 0);
                    if ($quantity <= 0) {
                        continue;
                    }
                    $amount = $item->getQuantity() > 0
                        ? $item->getTotalPrice() * $quantity / $item->getQuantity()
                        : 0.0;
                    $lines[] = $this->line($item, $quantity, $amount);
                }
                $refunds[] = $this->body(
                    $orderId,
                    'order:' . $orderUuid . ':cancelled',
                    $now,
                    $remaining,
                    $currency,
                    true,
                    $lines,
                );
            }
        }

        return $refunds;
    }

    private function wasPaid(OrderEntity $order): bool
    {
        foreach ($this->transactions($order) as $transaction) {
            if (in_array($this->stateOf($transaction), self::PAID_STATES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<OrderTransactionEntity>
     */
    private function transactions(OrderEntity $order): array
    {
        $transactions = $order->getTransactions();

        return $transactions === null ? [] : array_values($transactions->getElements());
    }

    private function stateOf(OrderTransactionEntity $transaction): string
    {
        return (string) ($transaction->getStateMachineState()?->getTechnicalName() ?? '');
    }

    /**
     * @return list<OrderTransactionCaptureRefundEntity>
     */
    private function completedCaptureRefunds(OrderTransactionEntity $transaction): array
    {
        $result = [];
        foreach ($transaction->getCaptures() ?? [] as $capture) {
            foreach ($capture->getRefunds() ?? [] as $refund) {
                if ($refund->getStateMachineState()?->getTechnicalName() === self::CAPTURE_REFUND_COMPLETED) {
                    $result[] = $refund;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<OrderLineItemEntity>
     */
    private function productLines(OrderEntity $order): array
    {
        $lines = [];
        foreach ($order->getLineItems() ?? [] as $item) {
            if ($this->isProductLine($item)) {
                $lines[] = $item;
            }
        }

        return $lines;
    }

    private function isProductLine(OrderLineItemEntity $item): bool
    {
        return $item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE;
    }

    /**
     * @return array{sku: string, externalId: string, quantity: int, amount: float}
     */
    private function line(OrderLineItemEntity $item, int $quantity, float $amount): array
    {
        $payload = $item->getPayload() ?? [];

        return [
            'sku'        => (string) ($payload['productNumber'] ?? ''),
            'externalId' => OrderEventNormalizer::externalIdFor($item->getProductId()),
            'quantity'   => $quantity,
            'amount'     => round($amount, 2),
        ];
    }

    /**
     * @param list<array{sku: string, externalId: string, quantity: int, amount: float}> $lines
     *
     * @return array<string, mixed>
     */
    private function body(
        string $orderId,
        string $refundId,
        \DateTimeInterface $refundedAt,
        float $amount,
        string $currency,
        bool $isCancellation,
        array $lines,
    ): array {
        return [
            'orderId'        => $orderId,
            'refundId'       => $refundId,
            'refundedAt'     => \DateTimeImmutable::createFromInterface($refundedAt)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'amount'         => round(max(0.0, $amount), 2),
            'currency'       => $currency,
            'isCancellation' => $isCancellation,
            'lines'          => $lines,
        ];
    }
}
