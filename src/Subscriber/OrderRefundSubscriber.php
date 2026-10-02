<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\Exception\SecretKeyRejectedException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\Normalizer\RefundEventNormalizer;
use AxitraceShopware6\ScheduledTask\FailedEventQueue;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reports refunds and cancellations to AxiTrace (`POST /v1/refund`), so
 * profit and POAS drop when money goes back to the shopper. ROAS and the
 * purchase already sent to the ad platforms are not touched by this.
 *
 * Listens to the same state machine events as the purchase
 * ({@see OrderPaidSubscriber}): an order transaction entering `refunded` or
 * `refunded_partially`, and the order entering `cancelled`. A paid transaction
 * cancelled directly (paid -> cancelled, which Shopware allows) is caught on
 * the generic transition event, the only one that carries the from state.
 * What is sent is decided by {@see RefundEventNormalizer}.
 *
 * Sent only when the merchant configured the AxiTrace secret key (the refund
 * endpoint accepts nothing else), the plugin is enabled for the Sales Channel,
 * and the purchase itself would have been forwarded under the consent mode -
 * a refund for an order AxiTrace never received would have nothing to reduce.
 *
 * Like the purchase subscriber this MUST NEVER throw into the state machine:
 * every throwable is caught and logged. An unreachable API queues the body
 * for the scheduled retry; the deterministic `refundId` makes resends safe.
 */
final class OrderRefundSubscriber implements EventSubscriberInterface
{
    private const ENTITY_TRANSACTION = 'order_transaction';
    private const STATE_CANCELLED = 'cancelled';
    /** States a transaction is in once the purchase was sent (it is sent on "paid"). */
    private const PAID_FROM_STATES = ['paid', 'refunded_partially'];

    public function __construct(
        private readonly PluginConfig $config,
        private readonly ConsentGate $consentGate,
        private readonly IngestionApiClient $ingestionClient,
        private readonly RefundEventNormalizer $normalizer,
        private readonly EntityRepository $orderRepository,
        private readonly FailedEventQueue $failedEventQueue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.refunded'           => 'onTransactionRefunded',
            'state_enter.order_transaction.state.refunded_partially' => 'onTransactionRefundedPartially',
            'state_enter.order.state.cancelled'                      => 'onOrderCancelled',
            StateMachineTransitionEvent::class                       => 'onTransition',
        ];
    }

    public function onTransactionRefunded(OrderStateMachineStateChangeEvent $event): void
    {
        $this->handle($event, RefundEventNormalizer::TRIGGER_REFUNDED);
    }

    public function onTransactionRefundedPartially(OrderStateMachineStateChangeEvent $event): void
    {
        $this->handle($event, RefundEventNormalizer::TRIGGER_REFUNDED_PARTIALLY);
    }

    public function onOrderCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $this->handle($event, RefundEventNormalizer::TRIGGER_CANCELLED);
    }

    /**
     * Only a transaction leaving a paid state for `cancelled` is of interest;
     * every other transition returns before any query.
     */
    public function onTransition(StateMachineTransitionEvent $event): void
    {
        if ($event->getEntityName() !== self::ENTITY_TRANSACTION
            || $event->getToPlace()->getTechnicalName() !== self::STATE_CANCELLED
            || !in_array($event->getFromPlace()->getTechnicalName(), self::PAID_FROM_STATES, true)
        ) {
            return;
        }

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('transactions.id', $event->getEntityId()));
            $this->dispatch($criteria, $event->getContext(), RefundEventNormalizer::TRIGGER_PAYMENT_CANCELLED);
        } catch (\Throwable $e) {
            $this->logger->critical('AxiTrace: refund dispatch failed (payment_cancelled): ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function handle(OrderStateMachineStateChangeEvent $event, string $trigger): void
    {
        try {
            $orderId = $event->getOrderId();
            if ($orderId === '') {
                return;
            }
            $this->dispatch(new Criteria([$orderId]), $event->getContext(), $trigger);
        } catch (\Throwable $e) {
            $this->logger->critical('AxiTrace: refund dispatch failed (' . $trigger . '): ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function dispatch(Criteria $criteria, Context $context, string $trigger): void
    {
        $criteria->addAssociation('currency');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('transactions.captures.refunds.stateMachineState');
        $criteria->addAssociation('transactions.captures.refunds.positions');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();
        if ($order === null) {
            $this->logger->critical('AxiTrace: order not found for refund (' . $trigger . ')');

            return;
        }

        $salesChannelId = (string) $order->getSalesChannelId();
        if (!$this->config->isEnabled($salesChannelId)) {
            return;
        }

        // Without the secret key refunds are not reported - an expected setup, not an error.
        $secretKey = $this->config->getSecretKey($salesChannelId);
        if ($secretKey === '') {
            return;
        }

        $decision = $order->getCustomFields()[OrderPlacedSubscriber::CUSTOM_FIELD_CONSENT] ?? null;
        if (!$this->consentGate->allowsServerTracking($this->config->getConsentMode($salesChannelId), is_string($decision) ? $decision : null)) {
            return;
        }

        $bodies = $this->normalizer->normalize($order, $trigger, new \DateTimeImmutable());

        if ($bodies === [] && $trigger === RefundEventNormalizer::TRIGGER_REFUNDED_PARTIALLY) {
            $this->logger->warning('AxiTrace: partial refund not reported - Shopware recorded no refund amount for it', [
                'order_number' => (string) $order->getOrderNumber(),
            ]);

            return;
        }

        foreach ($bodies as $body) {
            try {
                $this->ingestionClient->sendRefund($body, $secretKey);
            } catch (SecretKeyRejectedException) {
                // Logged at critical by the client. A refund is never sent without a
                // valid key, and resending with the same key cannot succeed: no queue.
                return;
            } catch (IngestionUnreachableException $e) {
                $this->failedEventQueue->enqueue(
                    (string) $body['refundId'],
                    $body,
                    FailedEventQueue::ENDPOINT_REFUND,
                    $salesChannelId,
                    $e,
                    $context,
                );
            }
        }
    }
}
