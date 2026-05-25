<?php

declare(strict_types=1);

namespace AxitraceShopware6\Config;

/**
 * AES-256-GCM encryption wrapper for sensitive config values (e.g. publicKey).
 *
 * Key is derived from APP_SECRET via SHA-256. Each encrypt call uses a fresh
 * 12-byte IV so two encryptions of the same plaintext produce different ciphertext.
 *
 * Wire format (base64): [12 bytes IV][16 bytes GCM tag][ciphertext bytes]
 */
final class AxitraceCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const MIN_ENCODED_BYTES = self::IV_LENGTH + self::TAG_LENGTH + 1;

    public function __construct(private readonly string $appSecret)
    {
    }

    /**
     * Encrypts plaintext with AES-256-GCM using a key derived from APP_SECRET.
     * Returns empty string unchanged (no encryption of empty values).
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = hash('sha256', $this->appSecret, true);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            return '';
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a base64-encoded ciphertext produced by encrypt().
     *
     * Returns empty string on any failure (wrong key, tampered bytes, too-short input).
     * Short / non-base64 input also returns empty string — callers may treat that as
     * a signal that the stored value is a legacy plaintext (unencrypted from v0.1.0).
     */
    public function decrypt(string $ciphertextBase64): string
    {
        if ($ciphertextBase64 === '') {
            return '';
        }

        $raw = base64_decode($ciphertextBase64, strict: true);

        if ($raw === false || strlen($raw) < self::MIN_ENCODED_BYTES) {
            return '';
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $key = hash('sha256', $this->appSecret, true);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            return '';
        }

        return $plaintext;
    }
}
