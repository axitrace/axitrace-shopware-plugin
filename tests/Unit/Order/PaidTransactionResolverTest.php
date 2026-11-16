<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Order;

use AxitraceShopware6\Order\PaidTransactionResolver;
use AxitraceShopware6\Tests\Unit\Normalizer\OrderFixtures;
use PHPUnit\Framework\TestCase;

final class PaidTransactionResolverTest extends TestCase
{
    private const SECOND_TRANSACTION_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1f002';

    public function testSinglePaidTransactionIsTheEventTransaction(): void
    {
        $order = OrderFixtures::order([], [OrderFixtures::transaction('paid')]);

        $resolver = new PaidTransactionResolver();

        self::assertSame(OrderFixtures::TRANSACTION_ID, $resolver->eventTransactionId($order));
        self::assertSame(OrderFixtures::TRANSACTION_ID, $resolver->paidTransactionId($order));
    }

    public function testSingleOpenTransactionKeysTheServerEventButIsNotPaid(): void
    {
        $order = OrderFixtures::order([], [OrderFixtures::transaction('open')]);

        $resolver = new PaidTransactionResolver();

        // The server-side purchase is only sent from the "paid" transition, so it
        // would use this transaction; the finish page must wait until it is paid.
        self::assertSame(OrderFixtures::TRANSACTION_ID, $resolver->eventTransactionId($order));
        self::assertNull($resolver->paidTransactionId($order));
    }

    public function testPaidTransactionWinsOverALaterFailedOne(): void
    {
        $paid = OrderFixtures::transaction('paid');
        $paid->setCreatedAt(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $failed = OrderFixtures::transaction('failed', id: self::SECOND_TRANSACTION_ID);
        $failed->setCreatedAt(new \DateTimeImmutable('2026-11-16 10:05:00'));
        $order = OrderFixtures::order([], [$paid, $failed]);

        $resolver = new PaidTransactionResolver();

        self::assertSame(OrderFixtures::TRANSACTION_ID, $resolver->eventTransactionId($order));
        self::assertSame(OrderFixtures::TRANSACTION_ID, $resolver->paidTransactionId($order));
    }

    public function testMostRecentlyUpdatedPaidTransactionWins(): void
    {
        $older = OrderFixtures::transaction('paid');
        $older->setCreatedAt(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $newer = OrderFixtures::transaction('paid', id: self::SECOND_TRANSACTION_ID);
        $newer->setCreatedAt(new \DateTimeImmutable('2026-11-16 09:00:00'));
        $newer->setUpdatedAt(new \DateTimeImmutable('2026-11-16 11:00:00'));
        $order = OrderFixtures::order([], [$older, $newer]);

        self::assertSame(self::SECOND_TRANSACTION_ID, (new PaidTransactionResolver())->paidTransactionId($order));
    }

    public function testNoPaidTransactionAmongSeveralFallsBackToTheLastForTheServerOnly(): void
    {
        $first = OrderFixtures::transaction('cancelled');
        $last = OrderFixtures::transaction('open', id: self::SECOND_TRANSACTION_ID);
        $order = OrderFixtures::order([], [$first, $last]);

        $resolver = new PaidTransactionResolver();

        self::assertSame(self::SECOND_TRANSACTION_ID, $resolver->eventTransactionId($order));
        self::assertNull($resolver->paidTransactionId($order));
    }

    public function testOrderWithoutTransactions(): void
    {
        $order = OrderFixtures::order([], []);

        $resolver = new PaidTransactionResolver();

        self::assertSame('', $resolver->eventTransactionId($order));
        self::assertNull($resolver->paidTransactionId($order));
    }
}
