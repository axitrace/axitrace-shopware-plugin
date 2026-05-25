<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Config;

use AxitraceShopware6\Config\AxitraceCrypto;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AES-256-GCM encryption / decryption in AxitraceCrypto.
 */
final class AxitraceCryptoTest extends TestCase
{
    private const APP_SECRET = 'test-app-secret-fixture';

    private AxitraceCrypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new AxitraceCrypto(self::APP_SECRET);
    }

    // -------------------------------------------------------------------------

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'pk_live_abcdef1234567890abcdef1234567890';

        $ciphertext = $this->crypto->encrypt($plaintext);
        $decrypted = $this->crypto->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
    }

    public function testEncryptedOutputDiffersEachCall(): void
    {
        $plaintext = 'pk_live_abcdef1234567890abcdef1234567890';

        $first = $this->crypto->encrypt($plaintext);
        $second = $this->crypto->encrypt($plaintext);

        // Different IV per call → ciphertext must differ
        self::assertNotSame($first, $second);

        // Both must still decrypt correctly
        self::assertSame($plaintext, $this->crypto->decrypt($first));
        self::assertSame($plaintext, $this->crypto->decrypt($second));
    }

    public function testEncryptEmptyReturnsEmpty(): void
    {
        self::assertSame('', $this->crypto->encrypt(''));
    }

    public function testDecryptEmptyReturnsEmpty(): void
    {
        self::assertSame('', $this->crypto->decrypt(''));
    }

    public function testDecryptTamperedReturnsEmpty(): void
    {
        $ciphertext = $this->crypto->encrypt('pk_live_abcdef1234567890abcdef1234567890');

        // Decode, flip a byte in the ciphertext region (after IV+tag = 28 bytes), re-encode
        $raw = base64_decode($ciphertext, strict: true);
        self::assertNotFalse($raw, 'Expected valid base64 ciphertext from encrypt()');

        // Flip a byte well into the ciphertext payload
        $tamperOffset = 30;
        $raw[$tamperOffset] = chr(ord($raw[$tamperOffset]) ^ 0xFF);

        $tampered = base64_encode($raw);

        self::assertSame('', $this->crypto->decrypt($tampered));
    }

    public function testDecryptNonBase64ReturnsEmpty(): void
    {
        self::assertSame('', $this->crypto->decrypt('not-valid-base64!!!'));
    }

    public function testDecryptTooShortReturnsEmpty(): void
    {
        // 27 bytes decoded < MIN_ENCODED_BYTES (29 = 12 IV + 16 tag + 1 min ciphertext)
        $short = base64_encode(str_repeat('x', 20));

        self::assertSame('', $this->crypto->decrypt($short));
    }

    public function testWrongKeyReturnsEmpty(): void
    {
        $ciphertext = $this->crypto->encrypt('pk_live_abcdef1234567890abcdef1234567890');

        $differentCrypto = new AxitraceCrypto('completely-different-secret');

        self::assertSame('', $differentCrypto->decrypt($ciphertext));
    }
}
