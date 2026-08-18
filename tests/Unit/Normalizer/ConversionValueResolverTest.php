<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Normalizer;

use AxitraceShopware6\Normalizer\ConversionValueBasis;
use AxitraceShopware6\Normalizer\ConversionValueResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure arithmetic — runs without a Shopware installation.
 *
 * Reference order used throughout: 100.00 products gross (7.7% Swiss VAT
 * included → 92.85 net) + 8.90 shipping gross (0.64 VAT → 8.26 net):
 *   amountTotal = 108.90, amountNet = 101.11, shippingGross = 8.90, shippingTax = 0.64
 */
final class ConversionValueResolverTest extends TestCase
{
    private ConversionValueResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ConversionValueResolver();
    }

    /**
     * @return iterable<string, array{ConversionValueBasis, float}>
     */
    public static function basisProvider(): iterable
    {
        yield 'gross total = amountTotal'            => [ConversionValueBasis::GrossTotal, 108.90];
        yield 'gross excl shipping = total - ship'   => [ConversionValueBasis::GrossExclShipping, 100.00];
        yield 'net total = amountNet'                => [ConversionValueBasis::NetTotal, 101.11];
        yield 'net excl shipping = net - ship net'   => [ConversionValueBasis::NetExclShipping, 92.85];
    }

    #[DataProvider('basisProvider')]
    public function testResolvesEachBasis(ConversionValueBasis $basis, float $expected): void
    {
        self::assertSame(
            $expected,
            $this->resolver->resolve($basis, 108.90, 101.11, 8.90, 0.64),
        );
    }

    public function testFreeShippingLeavesNetAndGrossVariantsEqualToTotals(): void
    {
        self::assertSame(108.90, $this->resolver->resolve(ConversionValueBasis::GrossExclShipping, 108.90, 101.11, 0.0, 0.0));
        self::assertSame(101.11, $this->resolver->resolve(ConversionValueBasis::NetExclShipping, 108.90, 101.11, 0.0, 0.0));
    }

    public function testResultIsRoundedToTwoDecimals(): void
    {
        // 10.005 - 0 → banker's-rounding-independent: round() half away from zero → 10.01
        self::assertSame(10.01, $this->resolver->resolve(ConversionValueBasis::GrossTotal, 10.005, 9.0, 0.0, 0.0));
        // Floating point residue from a subtraction must not leak into the payload.
        self::assertSame(91.2, $this->resolver->resolve(ConversionValueBasis::GrossExclShipping, 100.1, 90.0, 8.9, 0.6));
    }

    public function testNeverNegative(): void
    {
        // Inconsistent order (shipping larger than the recorded total) → 0, not a negative value.
        self::assertSame(0.0, $this->resolver->resolve(ConversionValueBasis::GrossExclShipping, 5.0, 4.6, 8.9, 0.6));
        self::assertSame(0.0, $this->resolver->resolve(ConversionValueBasis::NetExclShipping, 5.0, 4.6, 8.9, 0.6));
    }

    public function testDefaultBasisIsGrossTotal(): void
    {
        self::assertSame(ConversionValueBasis::GrossTotal, ConversionValueBasis::fromConfigValue(null));
        self::assertSame(ConversionValueBasis::GrossTotal, ConversionValueBasis::fromConfigValue(''));
        self::assertSame(ConversionValueBasis::GrossTotal, ConversionValueBasis::fromConfigValue('something_else'));
        self::assertSame(ConversionValueBasis::GrossTotal, ConversionValueBasis::fromConfigValue(42));
    }

    public function testConfigValuesParseCaseAndWhitespaceInsensitively(): void
    {
        self::assertSame(ConversionValueBasis::NetExclShipping, ConversionValueBasis::fromConfigValue(' NET_EXCL_SHIPPING '));
        self::assertSame(ConversionValueBasis::GrossExclShipping, ConversionValueBasis::fromConfigValue('gross_excl_shipping'));
        self::assertSame(ConversionValueBasis::NetTotal, ConversionValueBasis::fromConfigValue('net_total'));
    }
}
