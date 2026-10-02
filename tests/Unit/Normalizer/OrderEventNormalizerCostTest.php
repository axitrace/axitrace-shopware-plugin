<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Config\PinterestCatalogIdMode;
use AxitraceShopware6\Normalizer\ConversionValueBasis;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Cost fields on the purchase payload (0.5.0): `unitCost`, `externalId`,
 * `taxesIncluded`. The existing `tax` / `shipping` / revenue keys are covered
 * by OrderEventNormalizerTest and must not change.
 */
final class OrderEventNormalizerCostTest extends TestCase
{
    private OrderEventNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OrderEventNormalizer();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(\Shopware\Core\Checkout\Order\OrderEntity $order, bool $includeCosts): array
    {
        return $this->normalizer->normalize(
            $order,
            'evt-1',
            'pk_live_test',
            ConversionValueBasis::GrossTotal,
            PinterestCatalogIdMode::Legacy,
            $includeCosts,
        );
    }

    public function testNetPurchasePriceIsSentAsUnitCostInTheOrderCurrency(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(OrderFixtures::purchasePrice(20.0, 23.8)))]);

        $product = $this->normalize($order, true)['data']['products'][0];

        self::assertSame(['amount' => 20.0, 'currency' => 'EUR'], $product['unitCost']);
        self::assertSame('shopware:' . OrderFixtures::PRODUCT_ID, $product['externalId']);
    }

    public function testNoUnitCostWithoutTheSecretKeyEvenWhenAPurchasePriceExists(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(OrderFixtures::purchasePrice(20.0, 23.8)))]);

        $payload = $this->normalize($order, false);
        $product = $payload['data']['products'][0];

        self::assertArrayNotHasKey('unitCost', $product);
        self::assertStringNotContainsString('unitCost', (string) json_encode($payload));
        // externalId is a product reference, not cost data: always sent.
        self::assertSame('shopware:' . OrderFixtures::PRODUCT_ID, $product['externalId']);
    }

    public function testDefaultCallSendsNoUnitCost(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(OrderFixtures::purchasePrice(20.0, 23.8)))]);

        $product = $this->normalizer->normalize($order, 'evt-1', 'pk_live_test')['data']['products'][0];

        self::assertArrayNotHasKey('unitCost', $product);
    }

    public function testMissingPurchasePriceOmitsUnitCost(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(null))]);

        self::assertArrayNotHasKey('unitCost', $this->normalize($order, true)['data']['products'][0]);
    }

    public function testLineWithoutLoadedProductOmitsUnitCost(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(null)]);

        self::assertArrayNotHasKey('unitCost', $this->normalize($order, true)['data']['products'][0]);
    }

    public function testZeroPurchasePriceIsTreatedAsNotMaintained(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(OrderFixtures::purchasePrice(0.0, 0.0)))]);

        self::assertArrayNotHasKey('unitCost', $this->normalize($order, true)['data']['products'][0]);
    }

    public function testGrossOnlyLinkedPurchasePriceIsConvertedToNetWithTheLineTaxRate(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(
            OrderFixtures::product(OrderFixtures::purchasePrice(0.0, 23.8, linked: true)),
            taxRate: 19.0,
        )]);

        self::assertSame(['amount' => 20.0, 'currency' => 'EUR'], $this->normalize($order, true)['data']['products'][0]['unitCost']);
    }

    public function testGrossOnlyUnlinkedPurchasePriceIsNotGuessed(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(
            OrderFixtures::product(OrderFixtures::purchasePrice(0.0, 23.8, linked: false)),
        )]);

        self::assertArrayNotHasKey('unitCost', $this->normalize($order, true)['data']['products'][0]);
    }

    public function testPurchasePriceInAnotherCurrencyIsNotSent(): void
    {
        // Only a EUR purchase price, order paid in CHF: Shopware's default-currency
        // fallback would hand back the EUR amount labelled as CHF.
        $order = OrderFixtures::order(
            [OrderFixtures::lineItem(OrderFixtures::product(OrderFixtures::purchasePrice(20.0, 23.8)))],
            currencyId: OrderFixtures::CHF_ID,
            currencyIso: 'CHF',
        );

        self::assertArrayNotHasKey('unitCost', $this->normalize($order, true)['data']['products'][0]);
    }

    public function testVariantInheritsTheParentsPurchasePrice(): void
    {
        $parent = OrderFixtures::product(OrderFixtures::purchasePrice(12.5, 14.88));
        $parent->setId(OrderFixtures::PARENT_ID);
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(null, $parent))]);

        $product = $this->normalize($order, true)['data']['products'][0];

        self::assertSame(['amount' => 12.5, 'currency' => 'EUR'], $product['unitCost']);
        // The reference stays the variant actually sold.
        self::assertSame('shopware:' . OrderFixtures::PRODUCT_ID, $product['externalId']);
    }

    public function testTaxesIncludedFollowsTheOrderTaxStatus(): void
    {
        $line = OrderFixtures::lineItem(OrderFixtures::product(null));

        self::assertTrue($this->normalize(OrderFixtures::order([$line], taxStatus: 'gross'), false)['data']['taxesIncluded']);
        self::assertFalse($this->normalize(OrderFixtures::order([$line], taxStatus: 'net'), false)['data']['taxesIncluded']);
        self::assertFalse($this->normalize(OrderFixtures::order([$line], taxStatus: 'tax-free'), false)['data']['taxesIncluded']);
    }

    public function testTaxesIncludedIsOmittedWhenTheOrderHasNoTaxStatus(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(null))], taxStatus: null);

        self::assertArrayNotHasKey('taxesIncluded', $this->normalize($order, false)['data']);
    }

    public function testExistingTaxAndShippingKeysAreUnchanged(): void
    {
        $order = OrderFixtures::order([OrderFixtures::lineItem(OrderFixtures::product(OrderFixtures::purchasePrice(20.0, 23.8)))]);

        $without = $this->normalize($order, false)['data'];
        $with = $this->normalize($order, true)['data'];

        self::assertSame($without['tax'], $with['tax']);
        self::assertSame($without['shipping'], $with['shipping']);
        self::assertSame($without['revenue'], $with['revenue']);
        self::assertSame($without['value'], $with['value']);
    }
}
