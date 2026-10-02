<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;

/**
 * Resolves the NET purchase price (cost of goods) of one order line.
 *
 * Source: the product's `purchasePrices`, the purchase price a merchant
 * maintains on the product in the Shopware admin. A variant without its own
 * purchase price inherits the parent's, so the parent is read when the
 * variant's field is empty (the DAL does not resolve inheritance unless the
 * context asks for it).
 *
 * Rules, each one chosen so that no cost is better than a wrong cost - the
 * AxiTrace profit engine falls back to the workspace's default margin for a
 * line without a cost:
 *  - the price must be stored for the ORDER currency exactly; Shopware's
 *    usual fallback to the default currency is NOT applied, because a cost in
 *    another currency than the revenue is rejected by the server anyway;
 *  - a positive net amount is used as is;
 *  - when only the gross amount is set (net 0) and the price is `linked`
 *    (Shopware derives net from gross through the tax rate), net is derived
 *    from gross with the line's tax rate; an unlinked gross-only price or an
 *    unknown tax rate yields no cost;
 *  - a price of 0 net and 0 gross is "not maintained", not a free product.
 *
 * Pure: no I/O.
 */
final class PurchaseCostResolver
{
    public function resolveNetUnitCost(OrderLineItemEntity $item, string $orderCurrencyId): ?float
    {
        if ($orderCurrencyId === '') {
            return null;
        }

        $price = $this->findPrice($item->getProduct(), $orderCurrencyId);
        if ($price === null) {
            return null;
        }

        $net = $price->getNet();
        if ($net > 0.0) {
            return round($net, 4);
        }

        $gross = $price->getGross();
        if ($gross <= 0.0 || !$price->getLinked()) {
            return null;
        }

        $taxRate = $this->taxRate($item);
        if ($taxRate === null) {
            return null;
        }

        return round($gross / (1 + $taxRate / 100), 4);
    }

    private function findPrice(?ProductEntity $product, string $currencyId): ?Price
    {
        if ($product === null) {
            return null;
        }

        $prices = $product->getPurchasePrices();
        if ($prices === null || $prices->count() === 0) {
            $prices = $product->getParent()?->getPurchasePrices();
        }

        return $prices?->getCurrencyPrice($currencyId, false);
    }

    /**
     * The line's tax rate in percent: the rule the line was actually taxed
     * with, else the product's configured tax. Null when neither is known.
     */
    private function taxRate(OrderLineItemEntity $item): ?float
    {
        $rule = $item->getPrice()?->getTaxRules()->first();
        if ($rule !== null) {
            return $rule->getTaxRate();
        }

        $calculated = $item->getPrice()?->getCalculatedTaxes()->first();
        if ($calculated !== null) {
            return $calculated->getTaxRate();
        }

        $tax = $item->getProduct()?->getTax();

        return $tax?->getTaxRate();
    }
}
