<?php

declare(strict_types=1);

namespace AxitraceShopware6\Order;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Picks the order transaction a purchase event id is derived from.
 *
 * The purchase event id is UUID v5 of the order id and THIS transaction id, so
 * the server-side purchase (sent when a transaction enters "paid") and the
 * browser-pixel Purchase on the order confirmation page must pick the same one,
 * or Meta and TikTok receive two ids for one order and count it twice. Both
 * read it here.
 *
 * Expects the order's transactions loaded with their stateMachineState.
 */
final class PaidTransactionResolver
{
    private const STATE_PAID = 'paid';

    /**
     * The transaction the server-side purchase is keyed on: the only one when
     * the order has one, otherwise the most recently updated transaction in
     * state "paid", otherwise (a race with the state machine) the last one.
     * Empty when the order has no transactions.
     */
    public function eventTransactionId(OrderEntity $order): string
    {
        return (string) $this->eventTransaction($order)?->getId();
    }

    /**
     * The same transaction, but only when it already IS paid - the moment the
     * server-side purchase is sent. Null for an order still awaiting payment
     * (bank transfer, cash on delivery, an asynchronous payment that has not
     * confirmed yet): a browser Purchase for it could precede the server one by
     * more than the platforms' deduplication window, or stand for an order that
     * is never paid at all.
     */
    public function paidTransactionId(OrderEntity $order): ?string
    {
        $transaction = $this->eventTransaction($order);
        if ($transaction === null || !$this->isPaid($transaction)) {
            return null;
        }

        $id = (string) $transaction->getId();

        return $id !== '' ? $id : null;
    }

    private function eventTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return null;
        }

        if ($transactions->count() === 1) {
            return $transactions->first();
        }

        $paid = null;
        $latest = null;
        foreach ($transactions as $transaction) {
            if (!$this->isPaid($transaction)) {
                continue;
            }
            $updatedAt = $transaction->getUpdatedAt() ?? $transaction->getCreatedAt();
            if ($latest === null || ($updatedAt !== null && $updatedAt > $latest)) {
                $latest = $updatedAt;
                $paid = $transaction;
            }
        }

        return $paid ?? $transactions->last();
    }

    private function isPaid(OrderTransactionEntity $transaction): bool
    {
        return $transaction->getStateMachineState()?->getTechnicalName() === self::STATE_PAID;
    }
}
