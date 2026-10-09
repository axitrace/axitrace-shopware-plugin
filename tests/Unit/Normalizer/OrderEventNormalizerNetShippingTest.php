<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Config\PinterestCatalogIdMode;
use AxitraceShopware6\Normalizer\ConversionValueBasis;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

/**
 * `data.shipping` is the shipping the buyer paid, gross, whatever the order's
 * tax status (0.5.3). On a net-priced (B2B) order Shopware stores the shipping
 * total NET with the tax on top; reporting that net figure as gross made the
 * AxiTrace profit engine split the order's net revenue wrongly between
 * shipping and the product lines, and the "excl. shipping" value bases
 * subtracted too little.
 */
final class OrderEventNormalizerNetShippingTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function data(string $taxStatus, float $shippingTotal, float $shippingTax, ConversionValueBasis $basis): array
    {
        // 100 net + 19 VAT for the product, shipping on top; total 125 gross.
        $order = OrderFixtures::order([OrderFixtures::lineItem(null, 1, 100.0)], [], taxStatus: $taxStatus, amountTotal: 124.95);
        $order->setAmountNet(105.0);
        $taxes = new CalculatedTaxCollection($shippingTax > 0 ? [new CalculatedTax($shippingTax, 19.0, $shippingTotal)] : []);
        $order->setShippingCosts(new CalculatedPrice($shippingTotal, $shippingTotal, $taxes, new TaxRuleCollection()));

        return (new OrderEventNormalizer())->normalize($order, 'evt-1', 'pk_live_test', $basis, PinterestCatalogIdMode::Legacy)['data'];
    }

    public function testNetOrderReportsShippingGross(): void
    {
        $data = self::data('net', 5.0, 0.95, ConversionValueBasis::GrossTotal);

        self::assertSame(5.95, $data['shipping']);
        self::assertFalse($data['taxesIncluded']);
    }

    public function testNetOrderExclShippingBasesSubtractTheRealShipping(): void
    {
        self::assertSame(119.0, self::data('net', 5.0, 0.95, ConversionValueBasis::GrossExclShipping)['revenue']['amount']);
        self::assertSame(100.0, self::data('net', 5.0, 0.95, ConversionValueBasis::NetExclShipping)['revenue']['amount']);
    }

    public function testGrossOrderShippingIsUnchanged(): void
    {
        self::assertSame(5.95, self::data('gross', 5.95, 0.95, ConversionValueBasis::GrossTotal)['shipping']);
    }

    public function testTaxFreeOrderShippingIsItsTotal(): void
    {
        self::assertSame(5.0, self::data('tax-free', 5.0, 0.0, ConversionValueBasis::GrossTotal)['shipping']);
    }
}
