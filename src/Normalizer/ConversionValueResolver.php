<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

/**
 * Pure arithmetic behind {@see ConversionValueBasis}: turns the four order
 * amounts Shopware exposes into the single conversion value we report.
 *
 * Kept free of Shopware types so it is unit-testable without a Shopware
 * installation; {@see OrderEventNormalizer} extracts the amounts from the
 * OrderEntity and calls {@see self::resolve()}.
 *
 * Amount semantics (all in the order's presentation currency):
 *   - $amountTotal   OrderEntity::getAmountTotal()  — grand total incl. VAT + shipping
 *   - $amountNet     OrderEntity::getAmountNet()    — grand total excl. VAT, incl. shipping net
 *   - $shippingGross shipping costs incl. VAT
 *   - $shippingTax   VAT contained in the shipping costs
 *
 * Results are rounded to 2 decimals and never negative: an order whose
 * amounts do not add up (manual admin edits, exotic tax rules) reports 0
 * rather than a negative conversion value that ad platforms would reject.
 */
final class ConversionValueResolver
{
    public function resolve(
        ConversionValueBasis $basis,
        float $amountTotal,
        float $amountNet,
        float $shippingGross,
        float $shippingTax,
    ): float {
        $shippingNet = $shippingGross - $shippingTax;

        $value = match ($basis) {
            ConversionValueBasis::GrossTotal => $amountTotal,
            ConversionValueBasis::GrossExclShipping => $amountTotal - $shippingGross,
            ConversionValueBasis::NetTotal => $amountNet,
            ConversionValueBasis::NetExclShipping => $amountNet - $shippingNet,
        };

        return max(0.0, round($value, 2));
    }
}
