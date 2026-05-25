<?php

declare(strict_types=1);

namespace AxitraceShopware6\Config;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Per-Sales-Channel settings facade for the AxiTrace plugin.
 *
 * All getters accept an optional Sales Channel ID.  When null is passed,
 * Shopware falls back to the global default value — matching
 * SystemConfigService's own behaviour.
 *
 * The publicKey is stored encrypted via AxitraceCrypto (AES-256-GCM) so that
 * the raw system_config row never contains the plaintext key.
 * Legacy plaintext values (from v0.1.0 before encryption was introduced) are
 * transparently accepted as a fallback if decryption yields an empty string.
 */
final class PluginConfig
{
    private const CONFIG_DOMAIN = 'AxitraceShopware6.config.';
    private const RUNTIME_DOMAIN = 'AxitraceShopware6.runtime.';

    /** Regex that a valid AxiTrace public key must satisfy. */
    private const PUBLIC_KEY_REGEX = '/^pk_(live|test)_[a-f0-9]{32}$/';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly AxitraceCrypto $crypto,
        private readonly LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Config fields
    // -------------------------------------------------------------------------

    /**
     * Returns true only when both:
     *  - the master `enabled` flag is true (or unset — defaults to true), AND
     *  - a valid publicKey has been configured.
     */
    public function isEnabled(?string $salesChannelId = null): bool
    {
        $enabled = $this->systemConfigService->get(
            self::CONFIG_DOMAIN . 'enabled',
            $salesChannelId,
        );

        if ($enabled === false) {
            return false;
        }

        return $this->getPublicKey($salesChannelId) !== '';
    }

    /**
     * Returns the decrypted AxiTrace public key for the given Sales Channel.
     *
     * Returns empty string when:
     *  - the config key is not set, or
     *  - decryption fails (corrupted value), or
     *  - the decrypted value does not match the expected format.
     *
     * Legacy plaintext values (pre-encryption) are accepted transparently.
     */
    public function getPublicKey(?string $salesChannelId = null): string
    {
        $raw = (string) $this->systemConfigService->get(
            self::CONFIG_DOMAIN . 'publicKey',
            $salesChannelId,
        );

        if ($raw === '') {
            return '';
        }

        $decrypted = $this->crypto->decrypt($raw);

        // Transparent legacy fallback: if decryption produced nothing but the
        // raw value already matches the pk_ format, treat it as plaintext from
        // a pre-encryption installation (v0.1.0 → v0.1.1 migration path).
        if ($decrypted === '' && preg_match(self::PUBLIC_KEY_REGEX, $raw) === 1) {
            return $raw;
        }

        $resolved = $decrypted !== '' ? $decrypted : '';

        if ($resolved === '') {
            return '';
        }

        if (preg_match(self::PUBLIC_KEY_REGEX, $resolved) !== 1) {
            $this->logger->critical(
                'AxiTrace: publicKey failed format validation',
                ['sales_channel_id' => $salesChannelId],
            );

            return '';
        }

        return $resolved;
    }

    /**
     * Encrypts the public key and persists it to system_config.
     *
     * @throws \InvalidArgumentException when the key does not match pk_(live|test)_[hex32]
     */
    public function setPublicKey(string $publicKey, ?string $salesChannelId = null): void
    {
        if ($publicKey !== '' && preg_match(self::PUBLIC_KEY_REGEX, $publicKey) !== 1) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid AxiTrace public key format: "%s". Expected pk_(live|test)_[a-f0-9]{32}.',
                    $publicKey,
                ),
            );
        }

        $encrypted = $this->crypto->encrypt($publicKey);

        $this->systemConfigService->set(
            self::CONFIG_DOMAIN . 'publicKey',
            $encrypted,
            $salesChannelId,
        );
    }

    /**
     * Returns true when the debug mode flag is enabled for the given channel.
     */
    public function isDebugMode(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get(
            self::CONFIG_DOMAIN . 'debugMode',
            $salesChannelId,
        );
    }

    /**
     * Returns the merchant-preferred tracking domain for the browser SDK, or
     * empty string when not configured. Pattern: an apex-or-subdomain (no
     * scheme, no path, no port). Examples: "stat.axitrace.com",
     * "pixel.customer.com".
     *
     * When configured, the StorefrontSubscriber loads `axitrack.js` from
     * `https://{tracking_domain}/axitrack.js` and sets the browser SDK's
     * `apiUrl` to `https://{tracking_domain}`, enabling first-party cookie
     * placement via the customer's CNAME (CNAME → stat.axitrace.com).
     *
     * Server-side dispatch always uses the hardcoded `stat.axitrace.com` —
     * the tracking_domain field has no effect on `IngestionApiClient` (SSRF
     * mitigation). The two paths are independent.
     */
    public function getTrackingDomain(?string $salesChannelId = null): string
    {
        $raw = (string) $this->systemConfigService->get(
            self::CONFIG_DOMAIN . 'trackingDomain',
            $salesChannelId,
        );

        $domain = strtolower(trim($raw));

        // Strip any protocol the merchant may have accidentally pasted.
        $domain = preg_replace('#^https?://#', '', $domain) ?? '';
        // Strip trailing slash / path.
        $domain = explode('/', $domain, 2)[0];

        // Allowlist pattern: only DNS-valid hostnames (no port, no path).
        if ($domain !== '' && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain) !== 1) {
            $this->logger->critical(
                'AxiTrace: trackingDomain failed format validation, falling back to default',
                ['sales_channel_id' => $salesChannelId, 'raw' => $domain],
            );
            return '';
        }

        return $domain;
    }

    // -------------------------------------------------------------------------
    // Runtime failure counters (written by event dispatch, read by admin UI)
    // -------------------------------------------------------------------------

    /**
     * Increments the failure counter and records the timestamp + message for
     * the admin failure-visibility banner.
     */
    public function recordFailure(string $errorMessage, ?string $salesChannelId = null): void
    {
        $current = $this->getRecentFailureCount($salesChannelId);

        $this->systemConfigService->set(
            self::RUNTIME_DOMAIN . 'recent_failure_count',
            $current + 1,
            $salesChannelId,
        );

        $this->systemConfigService->set(
            self::RUNTIME_DOMAIN . 'last_failure_at',
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            $salesChannelId,
        );

        $this->systemConfigService->set(
            self::RUNTIME_DOMAIN . 'last_failure_message',
            substr($errorMessage, 0, 500),
            $salesChannelId,
        );
    }

    /**
     * Resets all failure counters — called after a successful event dispatch.
     */
    public function clearFailureCounters(?string $salesChannelId = null): void
    {
        $this->systemConfigService->set(
            self::RUNTIME_DOMAIN . 'recent_failure_count',
            0,
            $salesChannelId,
        );

        $this->systemConfigService->set(
            self::RUNTIME_DOMAIN . 'last_failure_at',
            null,
            $salesChannelId,
        );

        $this->systemConfigService->set(
            self::RUNTIME_DOMAIN . 'last_failure_message',
            null,
            $salesChannelId,
        );
    }

    public function getRecentFailureCount(?string $salesChannelId = null): int
    {
        return (int) $this->systemConfigService->get(
            self::RUNTIME_DOMAIN . 'recent_failure_count',
            $salesChannelId,
        );
    }

    public function getLastFailureAt(?string $salesChannelId = null): ?string
    {
        $value = $this->systemConfigService->get(
            self::RUNTIME_DOMAIN . 'last_failure_at',
            $salesChannelId,
        );

        return $value !== null ? (string) $value : null;
    }

    public function getLastFailureMessage(?string $salesChannelId = null): ?string
    {
        $value = $this->systemConfigService->get(
            self::RUNTIME_DOMAIN . 'last_failure_message',
            $salesChannelId,
        );

        return $value !== null ? (string) $value : null;
    }
}
