<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Real Shopware entities built through their own setters, for the cost and
 * refund tests. Every typed property a normalizer reads is initialised.
 */
final class OrderFixtures
{
    public const ORDER_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1a2b3';
    public const EUR_ID = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';
    public const CHF_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1c4f0';
    public const PRODUCT_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1d001';
    public const PARENT_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1d000';
    public const LINE_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1e001';
    public const TRANSACTION_ID = '0192a0b1c2d3e4f5a6b7c8d9e0f1f001';

    public static function purchasePrice(float $net, float $gross, bool $linked = true, string $currencyId = self::EUR_ID): PriceCollection
    {
        return new PriceCollection([new Price($currencyId, $net, $gross, $linked)]);
    }

    public static function product(?PriceCollection $purchasePrices, ?ProductEntity $parent = null): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId(self::PRODUCT_ID);
        if ($purchasePrices !== null) {
            $product->setPurchasePrices($purchasePrices);
        }
        if ($parent !== null) {
            $product->setParent($parent);
        }

        return $product;
    }

    public static function lineItem(?ProductEntity $product, int $quantity = 2, float $unitPrice = 59.5, float $taxRate = 19.0, string $id = self::LINE_ID): OrderLineItemEntity
    {
        $item = new OrderLineItemEntity();
        $item->setId($id);
        $item->setIdentifier($id);
        $item->setType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $item->setProductId(self::PRODUCT_ID);
        $item->setPayload(['productNumber' => 'SW-1001']);
        $item->setLabel('Desk chair');
        $item->setQuantity($quantity);
        $item->setUnitPrice($unitPrice);
        $item->setTotalPrice($unitPrice * $quantity);
        $item->setPrice(new CalculatedPrice(
            $unitPrice,
            $unitPrice * $quantity,
            new CalculatedTaxCollection(),
            new TaxRuleCollection([new TaxRule($taxRate)]),
            $quantity,
        ));
        if ($product !== null) {
            $item->setProduct($product);
        }

        return $item;
    }

    /**
     * @param list<OrderLineItemEntity>    $lineItems
     * @param list<OrderTransactionEntity> $transactions
     */
    public static function order(
        array $lineItems,
        array $transactions = [],
        string $currencyId = self::EUR_ID,
        string $currencyIso = 'EUR',
        ?string $taxStatus = 'gross',
        float $amountTotal = 119.0,
    ): OrderEntity {
        $currency = new CurrencyEntity();
        $currency->setId($currencyId);
        $currency->setIsoCode($currencyIso);

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId('sales-channel-001');
        $order->setOrderNumber('10042');
        $order->setCurrencyId($currencyId);
        $order->setCurrency($currency);
        $order->setAmountTotal($amountTotal);
        $order->setAmountNet(round($amountTotal / 1.19, 2));
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection()));
        if ($taxStatus !== null) {
            $order->setTaxStatus($taxStatus);
        }
        $order->setLineItems(new OrderLineItemCollection($lineItems));
        $order->setTransactions(new OrderTransactionCollection($transactions));

        return $order;
    }

    public static function transaction(string $state, float $amount = 119.0, string $id = self::TRANSACTION_ID): OrderTransactionEntity
    {
        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setStateMachineState(self::state($state));
        $transaction->setAmount(new CalculatedPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $transaction;
    }

    public static function state(string $technicalName): StateMachineStateEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId(md5($technicalName));
        $state->setTechnicalName($technicalName);

        return $state;
    }
}
