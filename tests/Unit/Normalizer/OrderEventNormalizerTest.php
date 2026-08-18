<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Normalizer\ConversionValueBasis;
use AxitraceShopware6\Normalizer\OrderEventNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OrderEventNormalizer.
 *
 * Shopware entity classes may not be autoloadable in all CI environments.
 * Each test guards itself with a class_exists() skip so the suite still runs
 * cleanly without a full Shopware installation.
 *
 * Anonymous-class stubs are used for entity mocking where the real class is
 * available, ensuring we exercise the actual type-hint on normalize().
 */
final class OrderEventNormalizerTest extends TestCase
{
    private OrderEventNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OrderEventNormalizer();
    }

    // ------------------------------------------------------------------
    // Helpers: build Shopware entity stubs via anonymous classes.
    // These extend real Shopware entity classes so they satisfy the
    // type-hint on normalize(). If the class does not exist the test is
    // skipped automatically.
    //
    // NOTE: anonymous class constructor promotion with "readonly" requires
    // PHP 8.1+. We use explicit property assignment for PHP 8.0 compat.
    // ------------------------------------------------------------------

    /**
     * @return \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
     */
    private function makeBillingAddress(
        string $city = 'Berlin',
        string $zip = '10115',
        string $phone = '+49123456789',
        ?string $countryIso = 'DE'
    ): object {
        if (!class_exists(\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity::class)) {
            $this->markTestSkipped('Shopware OrderAddressEntity not installed.');
        }

        $country = null;
        if ($countryIso !== null) {
            if (!class_exists(\Shopware\Core\System\Country\CountryEntity::class)) {
                $this->markTestSkipped('Shopware CountryEntity not installed.');
            }
            $isoArg = $countryIso;
            $country = new class ($isoArg) extends \Shopware\Core\System\Country\CountryEntity {
                /** @var string */
                private $isoValue;

                public function __construct(string $iso)
                {
                    $this->isoValue = $iso;
                }

                public function getIso(): string
                {
                    return $this->isoValue;
                }
            };
        }

        $cityArg    = $city;
        $zipArg     = $zip;
        $phoneArg   = $phone;
        $countryRef = $country;

        return new class ($cityArg, $zipArg, $phoneArg, $countryRef) extends \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity {
            /** @var string */
            private $cityValue;
            /** @var string */
            private $zipValue;
            /** @var string */
            private $phoneValue;
            /** @var \Shopware\Core\System\Country\CountryEntity|null */
            private $countryValue;

            public function __construct(string $city, string $zip, string $phone, $country)
            {
                $this->cityValue    = $city;
                $this->zipValue     = $zip;
                $this->phoneValue   = $phone;
                $this->countryValue = $country;
                $this->setFirstName('Erika');
                $this->setLastName('Mustermann');
            }

            public function getCity(): string
            {
                return $this->cityValue;
            }

            public function getZipcode(): string
            {
                return $this->zipValue;
            }

            public function getPhoneNumber(): ?string
            {
                return $this->phoneValue;
            }

            public function getCountry(): ?\Shopware\Core\System\Country\CountryEntity
            {
                return $this->countryValue;
            }
        };
    }

    /**
     * @return \Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
     */
    private function makeOrderCustomer(string $email = 'test@example.com'): object
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity::class)) {
            $this->markTestSkipped('Shopware OrderCustomerEntity not installed.');
        }

        $emailArg = $email;
        return new class ($emailArg) extends \Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity {
            /** @var string */
            private $emailValue;

            public function __construct(string $email)
            {
                $this->emailValue = $email;
            }

            public function getEmail(): string
            {
                return $this->emailValue;
            }
        };
    }

    /**
     * @return \Shopware\Core\System\Currency\CurrencyEntity
     */
    private function makeCurrency(string $isoCode = 'EUR'): object
    {
        if (!class_exists(\Shopware\Core\System\Currency\CurrencyEntity::class)) {
            $this->markTestSkipped('Shopware CurrencyEntity not installed.');
        }

        $iso = $isoCode;
        return new class ($iso) extends \Shopware\Core\System\Currency\CurrencyEntity {
            /** @var string */
            private $isoCodeValue;

            public function __construct(string $isoCode)
            {
                $this->isoCodeValue = $isoCode;
            }

            public function getIsoCode(): string
            {
                return $this->isoCodeValue;
            }
        };
    }

    /**
     * Builds a line-item collection containing one PRODUCT item and optionally
     * a second item with a different type (promotion).
     *
     * @return \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
     */
    private function makeLineItems(bool $includePromotion = false): object
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection::class)) {
            $this->markTestSkipped('Shopware OrderLineItemCollection not installed.');
        }
        if (!class_exists(\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity::class)) {
            $this->markTestSkipped('Shopware OrderLineItemEntity not installed.');
        }
        if (!class_exists(\Shopware\Core\Checkout\Cart\LineItem\LineItem::class)) {
            $this->markTestSkipped('Shopware LineItem not installed.');
        }

        $productItem = new class extends \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity {
            public function __construct()
            {
                $this->setId('item-1');
            }

            public function getType(): string
            {
                return \Shopware\Core\Checkout\Cart\LineItem\LineItem::PRODUCT_LINE_ITEM_TYPE;
            }

            public function getProductId(): ?string
            {
                return 'prod-abc-123';
            }

            public function getPayload(): ?array
            {
                return ['productNumber' => 'SKU-001'];
            }

            public function getLabel(): string
            {
                return 'Test Product';
            }

            public function getQuantity(): int
            {
                return 2;
            }

            public function getUnitPrice(): float
            {
                return 49.99;
            }
        };

        $items = [$productItem];

        if ($includePromotion) {
            $promotionItem = new class extends \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity {
                public function __construct()
                {
                    $this->setId('promo-1');
                }

                public function getType(): string
                {
                    return 'promotion';
                }

                public function getProductId(): ?string
                {
                    return null;
                }

                public function getPayload(): ?array
                {
                    return [];
                }

                public function getLabel(): string
                {
                    return '10% off coupon';
                }

                public function getQuantity(): int
                {
                    return 1;
                }

                public function getUnitPrice(): float
                {
                    return -10.0;
                }
            };
            $items[] = $promotionItem;
        }

        return new \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection($items);
    }

    /**
     * Builds a fully-populated OrderEntity stub.
     *
     * @return \Shopware\Core\Checkout\Order\OrderEntity
     */
    private function makeFullOrder(): object
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $billing   = $this->makeBillingAddress();
        $customer  = $this->makeOrderCustomer();
        $currency  = $this->makeCurrency('EUR');
        $lineItems = $this->makeLineItems();

        return new class ($billing, $customer, $currency, $lineItems) extends \Shopware\Core\Checkout\Order\OrderEntity {
            /** @var object */
            private $billingRef;
            /** @var object */
            private $customerRef;
            /** @var object */
            private $currencyRef;
            /** @var object */
            private $lineItemsRef;

            public function __construct(object $billing, object $customer, object $currency, object $lineItems)
            {
                $this->setId('order-uuid-001');
                $this->billingRef   = $billing;
                $this->customerRef  = $customer;
                $this->currencyRef  = $currency;
                $this->lineItemsRef = $lineItems;
            }

            public function getAmountTotal(): float
            {
                return 199.99;
            }

            // 199.99 gross = 185.17 net + 14.82 VAT (8.1%); shipping 9.90 gross incl. 0.74 VAT.
            public function getAmountNet(): float
            {
                return 185.17;
            }

            public function getShippingCosts(): \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice
            {
                return new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    9.90,
                    9.90,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection([
                        new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax(0.74, 8.1, 9.90),
                    ]),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                );
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                /** @var \Shopware\Core\System\Currency\CurrencyEntity $c */
                $c = $this->currencyRef;
                return $c;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity $b */
                $b = $this->billingRef;
                return $b;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity $c */
                $c = $this->customerRef;
                return $c;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection $l */
                $l = $this->lineItemsRef;
                return $l;
            }
        };
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Full order with all associations loaded — all expected keys present
     * with correct types.
     */
    public function testNormalizeFullOrder(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order   = $this->makeFullOrder();
        $payload = $this->normalizer->normalize($order, 'evt-uuid-001', 'pk_live_test');

        // Top-level keys
        self::assertSame('transaction.charge', $payload['event']);
        self::assertSame('evt-uuid-001', $payload['eventSalt']);
        self::assertSame('evt-uuid-001', $payload['event_id']);
        self::assertSame('evt-uuid-001', $payload['transactionId']);
        self::assertSame('order-uuid-001', $payload['orderId']);
        self::assertSame('pk_live_test', $payload['workspace_public_key']);
        self::assertSame('shopware', $payload['source']);
        self::assertIsString($payload['timestamp']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['timestamp']);
        self::assertSame('', $payload['ip']);
        self::assertSame('', $payload['userAgent']);
        self::assertIsString($payload['pluginVersion']);
        self::assertIsString($payload['sdkVersion']);
        self::assertSame('Berlin', $payload['billingCity']);
        self::assertSame('DE', $payload['billingCountry']);
        self::assertSame('10115', $payload['billingZip']);

        // data sub-keys
        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];

        self::assertSame('test@example.com', $data['client']['email']);
        self::assertSame('+49123456789', $data['client']['phone']);
        self::assertIsArray($data['products']);
        self::assertCount(1, $data['products']);

        $product = $data['products'][0];
        self::assertSame('prod-abc-123', $product['productId']);
        self::assertSame('SKU-001', $product['sku']);
        self::assertSame('Test Product', $product['name']);
        self::assertSame(2.0, $product['quantity']);
        self::assertSame(49.99, $product['price']);
        self::assertSame('EUR', $product['currency']);

        self::assertArrayHasKey('revenue', $data);
        self::assertArrayHasKey('value', $data);

        // orderNumber is always present (empty string when the order has none)
        self::assertSame('', $data['orderNumber']);
    }

    /**
     * Buyer context captured at order placement (customFields written by
     * OrderPlacedSubscriber) must surface in the payload: real IP/User-Agent
     * at top level, GA cookies and the order number inside `data`.
     */
    public function testBuyerContextFromCustomFieldsIsForwarded(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order = $this->makeFullOrder();
        $order->setOrderNumber('10042');
        $order->setCustomFields([
            \AxitraceShopware6\Subscriber\OrderPlacedSubscriber::CUSTOM_FIELD_FBP        => 'fb.1.1700000000.123456',
            \AxitraceShopware6\Subscriber\OrderPlacedSubscriber::CUSTOM_FIELD_FBC        => 'fb.1.1700000000.AbCdEf-123',
            \AxitraceShopware6\Subscriber\OrderPlacedSubscriber::CUSTOM_FIELD_CLIENT_IP  => '203.0.113.7',
            \AxitraceShopware6\Subscriber\OrderPlacedSubscriber::CUSTOM_FIELD_CLIENT_UA  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)',
            \AxitraceShopware6\Subscriber\OrderPlacedSubscriber::CUSTOM_FIELD_GA         => 'GA1.1.111222333.1700000001',
            \AxitraceShopware6\Subscriber\OrderPlacedSubscriber::CUSTOM_FIELD_GA_SESSION => 'GS2.1.s1700000002$o1$g1',
        ]);

        $payload = $this->normalizer->normalize($order, 'evt-ctx-1', 'pk_test');
        $data    = $payload['data'];

        self::assertSame('203.0.113.7', $payload['ip']);
        self::assertSame('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)', $payload['userAgent']);
        self::assertSame('fb.1.1700000000.123456', $data['fbp']);
        self::assertSame('fb.1.1700000000.AbCdEf-123', $data['fbc']);
        self::assertSame('GA1.1.111222333.1700000001', $data['_ga']);
        self::assertSame('GS2.1.s1700000002$o1$g1', $data['ga_session_id']);
        self::assertSame('10042', $data['orderNumber']);
    }

    /**
     * `data.revenue` and `data.value` must always be the object shape
     * { amount: float, currency: string } — never a bare float.
     */
    public function testRevenueIsAlwaysObjectShape(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order   = $this->makeFullOrder();
        $payload = $this->normalizer->normalize($order, 'evt-1', 'pk_test');
        $data    = $payload['data'];

        // revenue
        self::assertIsArray($data['revenue'], 'revenue must be an array (object shape), not a scalar.');
        self::assertArrayHasKey('amount', $data['revenue']);
        self::assertArrayHasKey('currency', $data['revenue']);
        self::assertIsFloat($data['revenue']['amount']);
        self::assertIsString($data['revenue']['currency']);

        // value — must be identical object shape, NOT a bare float
        self::assertIsArray($data['value'], 'value must be an array (object shape), not a scalar.');
        self::assertArrayHasKey('amount', $data['value']);
        self::assertArrayHasKey('currency', $data['value']);
        self::assertIsFloat($data['value']['amount']);
        self::assertIsString($data['value']['currency']);

        // Both must carry the same amount
        self::assertSame($data['revenue']['amount'], $data['value']['amount']);
        self::assertSame($data['revenue']['currency'], $data['value']['currency']);
    }

    /**
     * The merchant's "conversion value" setting selects which order amount is
     * reported; the default stays the historical gross total.
     */
    public function testConversionValueBasisControlsRevenueAmount(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order = $this->makeFullOrder();

        $default = $this->normalizer->normalize($order, 'evt-1', 'pk_test');
        self::assertSame(199.99, $default['data']['revenue']['amount']);
        self::assertSame('gross_total', $default['data']['valueBasis']);

        $exclShipping = $this->normalizer->normalize($order, 'evt-1', 'pk_test', ConversionValueBasis::GrossExclShipping);
        self::assertSame(190.09, $exclShipping['data']['revenue']['amount']);
        self::assertSame(190.09, $exclShipping['data']['value']['amount']);

        $net = $this->normalizer->normalize($order, 'evt-1', 'pk_test', ConversionValueBasis::NetTotal);
        self::assertSame(185.17, $net['data']['revenue']['amount']);

        // net excl. shipping = 185.17 - (9.90 - 0.74) = 176.01
        $netExclShipping = $this->normalizer->normalize($order, 'evt-1', 'pk_test', ConversionValueBasis::NetExclShipping);
        self::assertSame(176.01, $netExclShipping['data']['revenue']['amount']);
        self::assertSame('net_excl_shipping', $netExclShipping['data']['valueBasis']);
    }

    /**
     * Gross VAT and shipping ride along regardless of the selected basis so
     * GA4 gets tax/shipping params and merchants can reconstruct any basis.
     */
    public function testTaxAndShippingAreAlwaysReportedGross(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order = $this->makeFullOrder();

        foreach ([ConversionValueBasis::GrossTotal, ConversionValueBasis::NetExclShipping] as $basis) {
            $payload = $this->normalizer->normalize($order, 'evt-1', 'pk_test', $basis);
            self::assertSame(14.82, $payload['data']['tax']);
            self::assertSame(9.90, $payload['data']['shipping']);
        }
    }

    /**
     * When getCurrency() returns null the currency code must be an empty string
     * and no exception must be thrown.
     */
    public function testMissingCurrencyReturnsEmptyString(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order = new class extends \Shopware\Core\Checkout\Order\OrderEntity {
            public function __construct()
            {
                $this->setId('order-no-currency');
            }

            public function getAmountTotal(): float
            {
                return 50.00;
            }

            public function getAmountNet(): float
            {
                return 0.0;
            }

            public function getShippingCosts(): \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice
            {
                return new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    0.0,
                    0.0,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                );
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                return null;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                return null;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                return null;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                return null;
            }
        };

        $payload = $this->normalizer->normalize($order, 'evt-2', 'pk_test');

        self::assertSame('', $payload['data']['revenue']['currency']);
        self::assertSame('', $payload['data']['value']['currency']);
    }

    /**
     * When getBillingAddress() returns null all billing fields must be empty
     * strings and no exception must be thrown.
     */
    public function testMissingBillingAddressIsHandled(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $currency = $this->makeCurrency('USD');

        $order = new class ($currency) extends \Shopware\Core\Checkout\Order\OrderEntity {
            /** @var object */
            private $currencyRef;

            public function __construct(object $currency)
            {
                $this->setId('order-no-billing');
                $this->currencyRef = $currency;
            }

            public function getAmountTotal(): float
            {
                return 75.00;
            }

            public function getAmountNet(): float
            {
                return 0.0;
            }

            public function getShippingCosts(): \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice
            {
                return new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    0.0,
                    0.0,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                );
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                /** @var \Shopware\Core\System\Currency\CurrencyEntity $c */
                $c = $this->currencyRef;
                return $c;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                return null;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                return null;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                return null;
            }
        };

        $payload = $this->normalizer->normalize($order, 'evt-3', 'pk_test');

        self::assertSame('', $payload['billingCity']);
        self::assertSame('', $payload['billingCountry']);
        self::assertSame('', $payload['billingZip']);
        self::assertSame('', $payload['data']['client']['phone']);
    }

    /**
     * When getOrderCustomer() returns null client.email must be an empty string
     * and no exception must be thrown.
     */
    public function testMissingOrderCustomerIsHandled(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $currency = $this->makeCurrency('GBP');

        $order = new class ($currency) extends \Shopware\Core\Checkout\Order\OrderEntity {
            /** @var object */
            private $currencyRef;

            public function __construct(object $currency)
            {
                $this->setId('order-no-customer');
                $this->currencyRef = $currency;
            }

            public function getAmountTotal(): float
            {
                return 120.00;
            }

            public function getAmountNet(): float
            {
                return 0.0;
            }

            public function getShippingCosts(): \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice
            {
                return new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    0.0,
                    0.0,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                );
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                /** @var \Shopware\Core\System\Currency\CurrencyEntity $c */
                $c = $this->currencyRef;
                return $c;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                return null;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                return null;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                return null;
            }
        };

        $payload = $this->normalizer->normalize($order, 'evt-4', 'pk_test');

        self::assertSame('', $payload['data']['client']['email']);
    }

    /**
     * Non-product line items (e.g. type "promotion") must be excluded from the
     * products array; only the PRODUCT_LINE_ITEM_TYPE item must be included.
     */
    public function testNonProductLineItemsExcluded(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }
        if (!class_exists(\Shopware\Core\Checkout\Cart\LineItem\LineItem::class)) {
            $this->markTestSkipped('Shopware LineItem not installed.');
        }

        $currency  = $this->makeCurrency('EUR');
        $lineItems = $this->makeLineItems(true); // 2 items: 1 product + 1 promotion

        $order = new class ($currency, $lineItems) extends \Shopware\Core\Checkout\Order\OrderEntity {
            /** @var object */
            private $currencyRef;
            /** @var object */
            private $lineItemsRef;

            public function __construct(object $currency, object $lineItems)
            {
                $this->setId('order-mixed-items');
                $this->currencyRef  = $currency;
                $this->lineItemsRef = $lineItems;
            }

            public function getAmountTotal(): float
            {
                return 89.98;
            }

            public function getAmountNet(): float
            {
                return 0.0;
            }

            public function getShippingCosts(): \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice
            {
                return new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    0.0,
                    0.0,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                );
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                /** @var \Shopware\Core\System\Currency\CurrencyEntity $c */
                $c = $this->currencyRef;
                return $c;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                return null;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                return null;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection $l */
                $l = $this->lineItemsRef;
                return $l;
            }
        };

        $payload  = $this->normalizer->normalize($order, 'evt-5', 'pk_test');
        $products = $payload['data']['products'];

        self::assertCount(1, $products, 'Promotion line item must be excluded; only 1 product must remain.');
        self::assertSame('prod-abc-123', $products[0]['productId']);
    }

    /**
     * The source field must always be the lowercase string "shopware".
     */
    public function testSourceIsShopwareString(): void
    {
        if (!class_exists(\Shopware\Core\Checkout\Order\OrderEntity::class)) {
            $this->markTestSkipped('Shopware OrderEntity not installed.');
        }

        $order = new class extends \Shopware\Core\Checkout\Order\OrderEntity {
            public function __construct()
            {
                $this->setId('order-source-test');
            }

            public function getAmountTotal(): float
            {
                return 0.0;
            }

            public function getAmountNet(): float
            {
                return 0.0;
            }

            public function getShippingCosts(): \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice
            {
                return new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(
                    0.0,
                    0.0,
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(),
                    new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(),
                );
            }

            public function getCurrency(): ?\Shopware\Core\System\Currency\CurrencyEntity
            {
                return null;
            }

            public function getBillingAddress(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity
            {
                return null;
            }

            public function getOrderCustomer(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity
            {
                return null;
            }

            public function getLineItems(): ?\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection
            {
                return null;
            }
        };

        $payload = $this->normalizer->normalize($order, 'evt-6', 'pk_test');

        self::assertSame('shopware', $payload['source']);
    }
}
