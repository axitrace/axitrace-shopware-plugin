<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for the order_transaction paid state transition and POSTs a
 * transaction.charge event to the AxiTrace ingestion API.
 *
 * Shopware dispatches `state_enter.order_transaction.state.paid` with the
 * concrete event class `OrderStateMachineStateChangeEvent` (which exposes the
 * full OrderEntity directly — no two-step lookup needed). The associations
 * required for the AxiTrace payload (currency, billingAddress.country,
 * orderCustomer, lineItems) are NOT auto-loaded on the event-supplied order,
 * so the subscriber re-fetches the order with explicit addAssociation() calls
 * before handing it to OrderEventNormalizer.
 *
 * On IngestionUnreachableException the event payload is persisted to the
 * axitrace_failed_event_log table for later retry by
 * AxitraceRetryFailedEventsHandler.
 *
 * IMPORTANT: This subscriber MUST NEVER throw — an uncaught exception from an
 * event subscriber would abort the Shopware state machine transition and leave
 * the order in a broken state.  All throwables are caught at the top level of
 * onOrderPaid() and logged as critical.
 */
final class OrderPaidSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginConfig $config,
        private readonly IngestionApiClient $ingestionClient,
        private readonly OrderEventNormalizer $normalizer,
        private readonly UuidV5Generator $uuidGenerator,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $failedEventRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['state_enter.order_transaction.state.paid' => 'onOrderPaid'];
    }

    /**
     * Entry point — wraps the entire dispatch in a catch-all so that a failure
     * here can never abort the Shopware state machine transition.
     */
    public function onOrderPaid(OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->critical('AxiTrace: order paid event dispatch failed: ' . $e::class . ': ' . $e->getMessage());
            try {
                $this->config->recordFailure($e::class . ': ' . substr($e->getMessage(), 0, 200));
            } catch (\Throwable) {
            }
        }
    }

    private function dispatch(OrderStateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $orderId = $event->getOrderId();
        if ($orderId === '') {
            return;
        }

        // Re-fetch order with associations required for the AxiTrace payload.
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('currency');
        $orderCriteria->addAssociation('billingAddress.country');
        $orderCriteria->addAssociation('orderCustomer');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($orderCriteria, $context)->first();
        if ($order === null) {
            $this->logger->critical('AxiTrace: order not found for id=' . $orderId);
            return;
        }

        $salesChannelId = (string) $order->getSalesChannelId();

        if (!$this->config->isEnabled($salesChannelId)) {
            return;
        }
        $publicKey = $this->config->getPublicKey($salesChannelId);
        if ($publicKey === '') {
            $this->logger->critical('AxiTrace: public key not configured for sales channel ' . $salesChannelId);
            return;
        }

        // Resolve the transaction id that just transitioned to paid so the
        // UUID v5 composite key matches what the Go ingestion-api computes.
        $transactionId = $this->resolvePaidTransactionId($order);
        if ($transactionId === '') {
            $this->logger->critical('AxiTrace: paid transaction not found on order ' . $orderId);
            return;
        }

        $eventId = $this->uuidGenerator->forOrder($orderId, $transactionId);
        $payload = $this->normalizer->normalize($order, $eventId, $publicKey);

        try {
            $this->ingestionClient->sendEvent($payload);
            $this->config->clearFailureCounters($salesChannelId);
        } catch (IngestionUnreachableException $e) {
            $this->persistFailedEvent($eventId, $payload, $e, $context);
            $this->config->recordFailure($e->getMessage(), $salesChannelId);
        }
    }

    /**
     * Returns the id of the most-recent transaction on the order whose state is
     * "paid" — that is the transaction the OrderStateMachineStateChangeEvent
     * we are handling refers to.  If the order has only one transaction
     * (the common case), returns its id directly.
     */
    private function resolvePaidTransactionId(OrderEntity $order): string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return '';
        }

        if ($transactions->count() === 1) {
            return (string) $transactions->first()?->getId();
        }

        // Multi-transaction order — pick the most recent one in "paid" state.
        $paidTransactionId = '';
        $latest            = null;
        foreach ($transactions as $tx) {
            $state = $tx->getStateMachineState()?->getTechnicalName();
            if ($state !== 'paid') {
                continue;
            }
            $updatedAt = $tx->getUpdatedAt() ?? $tx->getCreatedAt();
            if ($latest === null || ($updatedAt !== null && $updatedAt > $latest)) {
                $latest            = $updatedAt;
                $paidTransactionId = (string) $tx->getId();
            }
        }

        // Fallback: if no transaction is in paid state (race condition), use the last one.
        if ($paidTransactionId === '') {
            $paidTransactionId = (string) $transactions->last()?->getId();
        }

        return $paidTransactionId;
    }

    private function persistFailedEvent(
        string $eventId,
        array $payload,
        \Throwable $error,
        Context $context,
    ): void {
        try {
            $this->failedEventRepository->create([[
                'id'            => Uuid::randomHex(),
                'eventId'       => $eventId,
                'payload'       => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'attempts'      => 0,
                'createdAt'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'lastAttemptAt' => null,
                'lastError'     => substr($error->getMessage(), 0, 500),
            ]], $context);
        } catch (\Throwable $persistError) {
            $class = $persistError::class;
            if (stripos($persistError->getMessage(), 'duplicate') === false
                && stripos($persistError->getMessage(), 'unique') === false
            ) {
                $this->logger->critical('AxiTrace: failed to persist failed-event row for ' . $eventId . ': ' . $class);
            }
        }
    }
}
