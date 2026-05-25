<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for the order_transaction paid state transition and POSTs a
 * transaction.charge event to the AxiTrace ingestion API.
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
        private readonly EntityRepository $orderTransactionRepository,
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
    public function onOrderPaid(StateMachineStateChangeEvent $event): void
    {
        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            // Subscriber MUST NEVER throw — state machine transition would fail
            $this->logger->critical('AxiTrace: order paid event dispatch failed: ' . $e::class . ': ' . $e->getMessage());
            // Best-effort failure counter increment (also defensively wrapped)
            try {
                $this->config->recordFailure($e::class . ': ' . substr($e->getMessage(), 0, 200));
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Orchestrates the full dispatch pipeline:
     *   1. Validate entity name is order_transaction
     *   2. Resolve the order via the transaction record
     *   3. Load the order with required associations
     *   4. Guard: plugin enabled + public key set
     *   5. Generate deterministic event_id (UUID v5)
     *   6. Normalize the order into a GeneratedEvent payload
     *   7. POST to ingestion API; on failure persist to retry queue
     */
    private function dispatch(StateMachineStateChangeEvent $event): void
    {
        // 1. Confirm event type is order_transaction
        if ($event->getTransition()->getEntityName() !== OrderTransactionDefinition::ENTITY_NAME) {
            return;
        }

        $context       = $event->getContext();
        $transactionId = $event->getTransition()->getEntityId();
        if ($transactionId === '') {
            return;
        }

        // 2. Resolve order id via the transaction lookup (two-step)
        $txCriteria = new Criteria([$transactionId]);
        $txEntity   = $this->orderTransactionRepository->search($txCriteria, $context)->first();
        if ($txEntity === null) {
            $this->logger->critical('AxiTrace: order transaction not found for id=' . $transactionId);
            return;
        }
        $orderId = (string) $txEntity->getOrderId();
        if ($orderId === '') {
            return;
        }

        // 3. Fetch the order WITH explicitly-loaded associations
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('currency');
        $orderCriteria->addAssociation('billingAddress.country');
        $orderCriteria->addAssociation('orderCustomer');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $order = $this->orderRepository->search($orderCriteria, $context)->first();
        if ($order === null) {
            $this->logger->critical('AxiTrace: order not found for id=' . $orderId);
            return;
        }

        // 4. Read salesChannelId from the order entity
        $salesChannelId = (string) $order->getSalesChannelId();

        // 5. Guard: plugin enabled + key set
        if (!$this->config->isEnabled($salesChannelId)) {
            return;
        }
        $publicKey = $this->config->getPublicKey($salesChannelId);
        if ($publicKey === '') {
            $this->logger->critical('AxiTrace: public key not configured for sales channel ' . $salesChannelId);
            return;
        }

        // 6. Generate the deterministic event_id using composite key (order + transaction)
        $eventId = $this->uuidGenerator->forOrder($orderId, $transactionId);

        // 7. Build the payload
        $payload = $this->normalizer->normalize($order, $eventId, $publicKey);

        // 8. POST — on IngestionUnreachableException, persist to retry queue
        try {
            $this->ingestionClient->sendEvent($payload);
            // Success — clear failure counters
            $this->config->clearFailureCounters($salesChannelId);
        } catch (IngestionUnreachableException $e) {
            // Persist to retry queue (UNIQUE on event_id prevents duplicate insertion)
            $this->persistFailedEvent($eventId, $payload, $e, $context);
            // Record failure counters
            $this->config->recordFailure($e->getMessage(), $salesChannelId);
            // Do NOT rethrow — see top-level try/catch in onOrderPaid
        }
    }

    /**
     * Persists the failed event payload to the axitrace_failed_event_log table
     * so that AxitraceRetryFailedEventsHandler can retry it later.
     *
     * Uses create() instead of upsert() so that a UNIQUE constraint violation
     * on event_id (duplicate state-machine fire for the same transaction) is
     * silently ignored — the event is already in the retry queue.
     */
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
            // Likely a UNIQUE constraint violation (duplicate state-machine fire) — safe to ignore.
            // Log only when it is NOT a duplicate-key error so we surface genuine failures.
            $class = $persistError::class;
            if (stripos($persistError->getMessage(), 'duplicate') === false
                && stripos($persistError->getMessage(), 'unique') === false
            ) {
                $this->logger->critical('AxiTrace: failed to persist failed-event row for ' . $eventId . ': ' . $class);
            }
        }
    }
}
