<?php

declare(strict_types=1);

namespace AxitraceShopware6\Domain;

/**
 * The answer to AxiTrace's store domain proof, `GET /axitrace/domain-proof?nonce=<nonce>`.
 *
 * When the plugin asks AxiTrace to verify a storefront host (StoreDomainClaimer),
 * AxiTrace fetches this path on that host with a one-time nonce. The answer names
 * the public key configured for the Sales Channel that serves the host (public by
 * design: every storefront page carries it) and, when that channel has a secret
 * key, an HMAC-SHA256 of `nonce|host|public_key` made with it. Only a shop
 * configured with the workspace's keys can produce it, so the domain is verified
 * without any DNS change by the merchant.
 *
 * The body has exactly the keys AxiTrace accepts; anything more is refused there.
 */
final class DomainProof
{
    public const PLATFORM = 'shopware';
    private const NONCE_REGEX = '/^[A-Za-z0-9_-]{16,128}$/';

    /**
     * @return array{status: int, body: array<string, int|string>}
     */
    public static function answer(string $nonce, string $host, string $publicKey, string $secretKey): array
    {
        if (preg_match(self::NONCE_REGEX, $nonce) !== 1) {
            return ['status' => 400, 'body' => ['error' => 'invalid_nonce']];
        }
        if ($publicKey === '') {
            return ['status' => 404, 'body' => ['error' => 'not_configured']];
        }

        $body = [
            'axitrace_domain_proof' => 1,
            'public_key'            => $publicKey,
            'nonce'                 => $nonce,
            'platform'              => self::PLATFORM,
        ];

        $host = self::normalizeHost($host);
        if ($secretKey !== '' && $host !== '') {
            $body['signature'] = hash_hmac('sha256', $nonce . '|' . $host . '|' . $publicKey, $secretKey);
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * A bare lower-case hostname (no scheme, port, path or trailing dot), or ''
     * when the value is not one. Accepts a full URL or a host.
     */
    public static function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = str_contains($value, '://') ? parse_url($value, \PHP_URL_HOST) : explode(':', $value, 2)[0];
        if (!\is_string($host)) {
            return '';
        }
        $host = rtrim(strtolower($host), '.');

        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) === 1 ? $host : '';
    }
}
