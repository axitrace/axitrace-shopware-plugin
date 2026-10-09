<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\EventId\UuidV5Generator;
use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\ScheduledTask\FailedEventQueue;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
        private readonly ConsentGate $consentGate,
        private readonly IngestionApiClient $ingestionClient,
        private readonly OrderEventNormalizer $normalizer,
        private readonly UuidV5Generator $uuidGenerator,
        private readonly EntityRepository $orderRepository,
        private readonly FailedEventQueue $failedEventQueue,
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
        $orderCriteria->addAssociation('billingAddress.countryState');
        $orderCriteria->addAssociation('orderCustomer');
        $orderCriteria->addAssociation('lineItems.product.manufacturer');
        $orderCriteria->addAssociation('lineItems.product.categories');
        // Purchase prices are inherited from the parent product when a variant has none.
        $orderCriteria->addAssociation('lineItems.product.parent');
        // State: picks the paid transaction among several. Payment method: selects
        // the merchant's payment fee rule in AxiTrace's profit engine.
        $orderCriteria->addAssociation('transactions.stateMachineState');
        $orderCriteria->addAssociation('transactions.paymentMethod');

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

        // Server-side consent gate (mode "all"): honour the decision recorded at
        // order placement. A missing decision (order placed before the upgrade,
        // or created in the admin / via API / by an import) is NOT forwarded —
        // fail-closed by design; see ConsentGate. The skip logs at warning (an
        // expected, not exceptional, outcome), carries no PII, and deliberately
        // does NOT touch recordFailure() or the failed-event log — a consent-
        // denied purchase must never be retried by the scheduled task.
        $consentMode = $this->config->getConsentMode($salesChannelId);
        $decision = $order->getCustomFields()[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null;
        if (!$this->consentGate->allowsServerTracking($consentMode, is_string($decision) ? $decision : null)) {
            $this->logger->warning('AxiTrace: purchase not forwarded — shopper consent not granted', [
                'order_number' => (string) $order->getOrderNumber(),
                'mode'         => $consentMode->value,
            ]);

            return;
        }

        // Resolve the transaction id that just transitioned to paid so the
        // UUID v5 composite key matches what the Go ingestion-api computes.
        $transactionId = $this->resolvePaidTransactionId($order);
        if ($transactionId === '') {
            $this->logger->critical('AxiTrace: paid transaction not found on order ' . $orderId);
            return;
        }

        // Cost data (per-line purchase price) travels only with the secret key:
        // the server keeps it only on a secret-key authenticated request.
        $secretKey = $this->config->getSecretKey($salesChannelId);

        $eventId = $this->uuidGenerator->forOrder($orderId, $transactionId);
        $payload = $this->normalizer->normalize(
            $order,
            $eventId,
            $publicKey,
            $this->config->getConversionValueBasis($salesChannelId),
            $this->config->getPinterestCatalogIdMode($salesChannelId),
            $secretKey !== '',
        );

        try {
            $this->ingestionClient->sendEvent($payload, $secretKey);
            $this->config->clearFailureCounters($salesChannelId);
        } catch (IngestionUnreachableException $e) {
            $this->failedEventQueue->enqueue($eventId, $payload, FailedEventQueue::ENDPOINT_PIXEL, $salesChannelId, $e, $context);
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
}
