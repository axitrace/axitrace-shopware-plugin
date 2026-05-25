<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\EventId;

use AxitraceShopware6\EventId\UuidV5Generator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies UuidV5Generator produces RFC 4122 §4.3 UUID v5 output that is
 * byte-identical to the Go ingestion-api counterpart in shopware_pixel.go.
 *
 * Cross-language parity vector (confirmed against php -r sha1 reference):
 *   orderId       = "019503e4-1234-7abc-8def-0123456789ab"
 *   transactionId = "TRANS-UUID-HERE"
 *   composite key = "shopware_order:019503e4-1234-7abc-8def-0123456789ab:txTRANS-UUID-HERE"
 *   namespace     = "5e5e5e5e-5e5e-5e5e-5e5e-5e5e5e5e5e5e"
 *   expected UUID = "a86a3492-94e6-585c-bea8-fe689277774a"
 *
 * The same inputs and expected output are used by the Go unit test so that
 * both sides of the pipeline can be verified independently. See
 * tests/fixtures/uuid_parity_vector.txt.
 */
final class UuidV5GeneratorTest extends TestCase
{
    private UuidV5Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new UuidV5Generator();
    }

    /**
     * Parity test: PHP output must match Go uuid.NewSHA1() for the same inputs.
     *
     * Expected value computed via:
     *   php -r '$ns = hex2bin(str_replace("-","","5e5e5e5e-5e5e-5e5e-5e5e-5e5e5e5e5e5e"));
     *           $key = "shopware_order:019503e4-1234-7abc-8def-0123456789ab:txTRANS-UUID-HERE";
     *           $hash = sha1($ns . $key);
     *           printf("%08s-%04s-%04x-%04x-%12s\n",
     *               substr($hash,0,8), substr($hash,8,4),
     *               (hexdec(substr($hash,12,4)) & 0x0fff) | 0x5000,
     *               (hexdec(substr($hash,16,4)) & 0x3fff) | 0x8000,
     *               substr($hash,20,12));'
     * Result: a86a3492-94e6-585c-bea8-fe689277774a
     */
    public function testForOrderMatchesGoOutput(): void
    {
        $result = $this->generator->forOrder(
            '019503e4-1234-7abc-8def-0123456789ab',
            'TRANS-UUID-HERE'
        );

        self::assertSame('a86a3492-94e6-585c-bea8-fe689277774a', $result);
    }

    /**
     * Empty orderId must raise InvalidArgumentException.
     */
    public function testEmptyOrderIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->forOrder('', 'TRANS-UUID-HERE');
    }

    /**
     * Empty transactionId must raise InvalidArgumentException.
     */
    public function testEmptyTransactionIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->forOrder('019503e4-1234-7abc-8def-0123456789ab', '');
    }

    /**
     * UUID v5 is deterministic: identical inputs must always produce identical output.
     */
    public function testDeterminism(): void
    {
        $a = $this->generator->forOrder(
            '019503e4-1234-7abc-8def-0123456789ab',
            'TRANS-UUID-HERE'
        );
        $b = $this->generator->forOrder(
            '019503e4-1234-7abc-8def-0123456789ab',
            'TRANS-UUID-HERE'
        );

        self::assertSame($a, $b, 'UUID v5 must be deterministic for identical inputs.');
    }

    /**
     * Output must conform to UUID v5 shape: version nibble = 5, variant = 10xxxxxx.
     */
    public function testOutputMatchesUuidV5Regex(): void
    {
        $result = $this->generator->forOrder(
            '019503e4-1234-7abc-8def-0123456789ab',
            'TRANS-UUID-HERE'
        );

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result,
            'Output must match UUID v5 canonical form.'
        );
    }
}
