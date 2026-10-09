<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

/**
 * `data.paymentInfo.method` (0.5.3): the payment method AxiTrace stores on the
 * order and selects the merchant's payment fee rule with. Before 0.5.3 it was
 * never sent, so a Shopware order always fell back to the default fee.
 */
final class OrderEventNormalizerPaymentMethodTest extends TestCase
{
    private static function method(?string $technicalName, ?string $name = null): PaymentMethodEntity
    {
        $method = new PaymentMethodEntity();
        $method->setId(md5((string) $technicalName . (string) $name));
        if ($technicalName !== null) {
            $method->setTechnicalName($technicalName);
        }
        if ($name !== null) {
            $method->setName($name);
        }

        return $method;
    }

    private static function tx(string $id, string $state, ?PaymentMethodEntity $method, string $createdAt): OrderTransactionEntity
    {
        $transaction = OrderFixtures::transaction($state, 119.0, $id);
        if ($method !== null) {
            $transaction->setPaymentMethod($method);
        }
        $transaction->setCreatedAt(new \DateTimeImmutable($createdAt));

        return $transaction;
    }

    /**
     * @param list<OrderTransactionEntity> $transactions
     *
     * @return array<string, mixed>
     */
    private static function data(array $transactions): array
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)], $transactions);

        return (new OrderEventNormalizer())->normalize($order, 'evt-1', 'pk_live_test')['data'];
    }

    public function testTechnicalNameOfThePaidTransactionIsSent(): void
    {
        $data = self::data([
            self::tx('tx-1', 'paid', self::method('payment_prepayment', 'Paid in advance'), '2026-10-09 10:00:00'),
        ]);

        self::assertSame(['method' => 'payment_prepayment'], $data['paymentInfo']);
    }

    public function testPaidTransactionWinsOverANewerFailedOne(): void
    {
        $data = self::data([
            self::tx('tx-1', 'paid', self::method('payment_paypal'), '2026-10-09 10:00:00'),
            self::tx('tx-2', 'failed', self::method('payment_invoicepayment'), '2026-10-09 11:00:00'),
        ]);

        self::assertSame('payment_paypal', $data['paymentInfo']['method']);
    }

    public function testLatestTransactionIsUsedWhenNoneIsPaid(): void
    {
        $data = self::data([
            self::tx('tx-1', 'cancelled', self::method('payment_paypal'), '2026-10-09 10:00:00'),
            self::tx('tx-2', 'open', self::method('payment_cashpayment'), '2026-10-09 11:00:00'),
        ]);

        self::assertSame('payment_cashpayment', $data['paymentInfo']['method']);
    }

    public function testNameIsTheFallbackWithoutATechnicalName(): void
    {
        $data = self::data([self::tx('tx-1', 'paid', self::method(null, 'Card at the till'), '2026-10-09 10:00:00')]);

        self::assertSame('Card at the till', $data['paymentInfo']['method']);
    }

    public function testOmittedWhenThePaymentMethodWasNotLoaded(): void
    {
        self::assertArrayNotHasKey('paymentInfo', self::data([self::tx('tx-1', 'paid', null, '2026-10-09 10:00:00')]));
        self::assertArrayNotHasKey('paymentInfo', self::data([]));
    }
}
