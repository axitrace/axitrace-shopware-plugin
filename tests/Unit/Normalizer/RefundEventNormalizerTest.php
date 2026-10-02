<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use AxitraceShopware6\Normalizer\RefundEventNormalizer;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefundPosition\OrderTransactionCaptureRefundPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefundPosition\OrderTransactionCaptureRefundPositionEntity;

/**
 * The `/v1/refund` bodies: contract keys, deterministic refund ids, and which
 * source of amounts wins.
 */
final class RefundEventNormalizerTest extends TestCase
{
    private const CAPTURE_REFUND_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1aa01';

    private RefundEventNormalizer $normalizer;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->normalizer = new RefundEventNormalizer();
        $this->now = new \DateTimeImmutable('2026-10-02 12:30:00', new \DateTimeZone('Europe/Berlin'));
    }

    public function testFullRefundSendsTheTransactionAmountWithEveryProductLine(): void
    {
        $order = OrderFixtures::order(
            [OrderFixtures::lineItem(null, quantity: 2, unitPrice: 59.5)],
            [OrderFixtures::transaction('refunded', 119.0)],
        );

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED, $this->now);

        self::assertSame([[
            'orderId'        => '10042',
            'refundId'       => 'tx:' . OrderFixtures::TRANSACTION_ID . ':refunded',
            'refundedAt'     => '2026-10-02T10:30:00Z',
            'amount'         => 119.0,
            'currency'       => 'EUR',
            'isCancellation' => false,
            'lines'          => [[
                'sku'        => 'SW-1001',
                'externalId' => 'shopware:' . OrderFixtures::PRODUCT_ID,
                'quantity'   => 2,
                'amount'     => 119.0,
            ]],
        ]], $bodies);
    }

    public function testRefundIdIsStableAcrossRepeatedTransitions(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded')]);

        $first = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED, $this->now);
        $second = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED, $this->now->modify('+1 hour'));

        self::assertSame($first[0]['refundId'], $second[0]['refundId']);
        self::assertLessThanOrEqual(64, strlen($first[0]['refundId']), 'refundId doubles as the retry-queue key (VARCHAR 64)');
    }

    public function testCompletedCaptureRefundIsSentWithItsOwnIdAmountAndPositions(): void
    {
        $line = OrderFixtures::lineItem(null, quantity: 2, unitPrice: 59.5);
        $transaction = OrderFixtures::transaction('refunded_partially');
        $this->attachCaptureRefund($transaction, 'completed', 59.5, 1);
        $order = OrderFixtures::order([$line], [$transaction]);

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED_PARTIALLY, $this->now);

        self::assertCount(1, $bodies);
        self::assertSame('refund:' . self::CAPTURE_REFUND_ID, $bodies[0]['refundId']);
        self::assertSame(59.5, $bodies[0]['amount']);
        self::assertSame('2026-09-30T08:00:00Z', $bodies[0]['refundedAt'], 'a recorded refund keeps its own date');
        self::assertFalse($bodies[0]['isCancellation']);
        self::assertSame([[
            'sku'        => 'SW-1001',
            'externalId' => 'shopware:' . OrderFixtures::PRODUCT_ID,
            'quantity'   => 1,
            'amount'     => 59.5,
        ]], $bodies[0]['lines']);
    }

    public function testCaptureRefundThatIsNotCompletedIsIgnored(): void
    {
        $transaction = OrderFixtures::transaction('refunded_partially');
        $this->attachCaptureRefund($transaction, 'open', 59.5, 1);
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [$transaction]);

        self::assertSame([], $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED_PARTIALLY, $this->now));
    }

    public function testPartialRefundWithoutAnyRecordedAmountSendsNothing(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded_partially')]);

        self::assertSame([], $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED_PARTIALLY, $this->now));
    }

    public function testCancellationOfAPaidOrderSendsTheFullAmount(): void
    {
        $order = OrderFixtures::order(
            [OrderFixtures::lineItem(null, quantity: 2, unitPrice: 59.5)],
            [OrderFixtures::transaction('paid')],
            amountTotal: 119.0,
        );

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_CANCELLED, $this->now);

        self::assertCount(1, $bodies);
        self::assertSame('order:' . OrderFixtures::ORDER_ID . ':cancelled', $bodies[0]['refundId']);
        self::assertTrue($bodies[0]['isCancellation']);
        self::assertSame(119.0, $bodies[0]['amount']);
        self::assertSame(2, $bodies[0]['lines'][0]['quantity']);
    }

    public function testCancellationOfAnUnpaidOrderSendsNothing(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('open')]);

        self::assertSame([], $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_CANCELLED, $this->now));
    }

    public function testCancellationAfterAFullRefundAddsNoSecondReduction(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded', 119.0)], amountTotal: 119.0);

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_CANCELLED, $this->now);

        self::assertCount(1, $bodies, 'only the (deduplicated) refund itself, no cancellation on top');
        self::assertFalse($bodies[0]['isCancellation']);
    }

    public function testCancellationAfterAPartialRefundSendsOnlyTheRemainder(): void
    {
        $transaction = OrderFixtures::transaction('paid');
        $this->attachCaptureRefund($transaction, 'completed', 59.5, 1);
        $order = OrderFixtures::order([OrderFixtures::lineItem(null, quantity: 2, unitPrice: 59.5)], [$transaction], amountTotal: 119.0);

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_CANCELLED, $this->now);

        self::assertCount(2, $bodies);
        $cancellation = $bodies[1];
        self::assertTrue($cancellation['isCancellation']);
        self::assertSame(59.5, $cancellation['amount']);
        self::assertSame(1, $cancellation['lines'][0]['quantity']);
        self::assertSame(59.5, $cancellation['lines'][0]['amount']);
    }

    public function testPaymentCancelledSharesTheOrderCancellationId(): void
    {
        // paid -> cancelled: the transaction no longer looks paid, the caller saw the from state.
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('cancelled')]);

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_PAYMENT_CANCELLED, $this->now);
        $orderCancel = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_CANCELLED, $this->now);

        self::assertCount(1, $bodies);
        self::assertSame('order:' . OrderFixtures::ORDER_ID . ':cancelled', $bodies[0]['refundId']);
        self::assertTrue($bodies[0]['isCancellation']);
        self::assertSame([], $orderCancel, 'the later order cancellation sees no paid transaction and sends nothing');
    }

    /**
     * AxiTrace stores a Shopware purchase under `data.orderNumber` when it is
     * non-empty, else under `orderId`; the refund is matched on its `orderId`.
     */
    public function testRefundOrderIdIsTheIdentifierThePurchaseIsStoredUnder(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded')]);

        $purchase = (new OrderEventNormalizer())->normalize($order, 'evt-1', 'pk_live_test');
        $refunds = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED, $this->now);

        self::assertSame('10042', $purchase['data']['orderNumber']);
        self::assertSame($purchase['data']['orderNumber'], $refunds[0]['orderId']);
        self::assertNotSame(OrderFixtures::ORDER_ID, $refunds[0]['orderId'], 'the order UUID never matches a purchase stored under its number');
    }

    public function testRefundFallsBackToTheOrderUuidWhenTheOrderHasNoNumber(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('refunded')]);
        $order->assign(['orderNumber' => null]);

        $purchase = (new OrderEventNormalizer())->normalize($order, 'evt-1', 'pk_live_test');
        $refunds = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_REFUNDED, $this->now);

        self::assertSame('', $purchase['data']['orderNumber']);
        self::assertSame($purchase['orderId'], $refunds[0]['orderId']);
        self::assertSame(OrderFixtures::ORDER_ID, $refunds[0]['orderId']);
    }

    public function testCancellationCarriesTheStoredOrderIdAndAUuidKeyedRefundId(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], [OrderFixtures::transaction('paid')]);

        $bodies = $this->normalizer->normalize($order, RefundEventNormalizer::TRIGGER_CANCELLED, $this->now);

        self::assertSame('10042', $bodies[0]['orderId']);
        self::assertSame('order:' . OrderFixtures::ORDER_ID . ':cancelled', $bodies[0]['refundId'], 'refund ids stay stable even if a number is assigned later');
    }

    private function attachCaptureRefund(OrderTransactionEntity $transaction, string $state, float $amount, int $quantity): void
    {
        $price = new CalculatedPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity);

        $position = new OrderTransactionCaptureRefundPositionEntity();
        $position->setId('0192a0b1c2d3e4f5a6b7c8d9e0f1bb01');
        $position->setOrderLineItemId(OrderFixtures::LINE_ID);
        $position->setQuantity($quantity);
        $position->setAmount($price);

        $refund = new OrderTransactionCaptureRefundEntity();
        $refund->setId(self::CAPTURE_REFUND_ID);
        $refund->setAmount($price);
        $refund->setStateMachineState(OrderFixtures::state($state));
        $refund->setPositions(new OrderTransactionCaptureRefundPositionCollection([$position]));
        $refund->setCreatedAt(new \DateTimeImmutable('2026-09-30T08:00:00Z'));

        $capture = new OrderTransactionCaptureEntity();
        $capture->setId('0192a0b1c2d3e4f5a6b7c8d9e0f1cc01');
        $capture->setRefunds(new OrderTransactionCaptureRefundCollection([$refund]));

        $transaction->setCaptures(new OrderTransactionCaptureCollection([$capture]));
    }
}
