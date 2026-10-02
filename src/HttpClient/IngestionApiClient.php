<?php

declare(strict_types=1);

namespace AxitraceShopware6\HttpClient;

use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\Exception\SecretKeyRejectedException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IngestionApiClient
{
    public const DEFAULT_API_BASE_URL = 'https://stat.axitrace.com';
    public const ENDPOINT_PATH = '/shopware/pixel';
    public const REFUND_ENDPOINT_PATH = '/v1/refund';
    private const TIMEOUT_SECONDS = 2;
    private const MAX_DURATION_SECONDS = 2;

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        ?string $apiBaseUrlOverride = null,
    ) {
        $this->baseUrl = $this->resolveBaseUrl($apiBaseUrlOverride);
    }

    /**
     * Sends a purchase (or any pixel event) to `/shopware/pixel`.
     *
     * With a non-empty secret key the request carries
     * `Authorization: Basic base64(<secret_key>:)`, which is what makes the
     * server keep the per-line `unitCost` fields; without it the server strips
     * them. Callers only put cost data into the payload when they also pass the key.
     *
     * A wrong key must never cost the merchant a purchase: when AxiTrace answers
     * 401 to a request sent with the key, the rejection is logged at critical
     * (without the key) and the purchase is sent ONCE more, without the
     * Authorization header and without any `unitCost`, exactly as a shop without
     * a key would send it. Only that resend's failure reaches the caller.
     *
     * @param array<string, mixed> $payload
     *
     * @throws IngestionUnreachableException when the purchase could not be delivered
     */
    public function sendEvent(array $payload, string $secretKey = ''): void
    {
        try {
            $this->post(self::ENDPOINT_PATH, $payload, $secretKey);
        } catch (SecretKeyRejectedException) {
            $this->post(self::ENDPOINT_PATH, self::withoutUnitCosts($payload), '');
        }
    }

    /**
     * Sends a refund or cancellation to `/v1/refund`. That endpoint accepts
     * secret-key authentication only, so an empty key is a caller error.
     * A refund is never sent without a valid key: a 401 surfaces as
     * {@see SecretKeyRejectedException} (already logged at critical here), which
     * callers must not queue for a retry.
     *
     * @param array<string, mixed> $payload
     *
     * @throws SecretKeyRejectedException    when AxiTrace rejected the key
     * @throws IngestionUnreachableException when the refund could not be delivered
     */
    public function sendRefund(array $payload, string $secretKey): void
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('AxiTrace refunds require the secret key.');
        }

        $this->post(self::REFUND_ENDPOINT_PATH, $payload, $secretKey);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload, string $secretKey): void
    {
        $headers = ['Accept' => 'application/json'];
        if ($secretKey !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($secretKey . ':');
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . $path, [
                'json'         => $payload,
                'timeout'      => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'headers'      => $headers,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            // PII-safe: log only exception class name, NOT message (URL may contain query params with PII)
            $this->logger->critical('AxiTrace ingestion-api transport failure: path=' . $path . ' class=' . $e::class);
            throw new IngestionUnreachableException('transport error', 0, $e);
        }

        if ($status === 401 && $secretKey !== '') {
            // Never log the key itself, not even a prefix of it.
            $this->logger->critical(sprintf(
                'AxiTrace: the secret key configured in the plugin was rejected (HTTP 401) on path=%s. '
                . 'Check that it is the Secret Key of the same workspace as the public key. '
                . 'Purchases are sent without product costs and refunds are not reported until it is fixed.',
                $path,
            ));
            throw new SecretKeyRejectedException('HTTP 401 for the configured secret key');
        }

        if ($status < 200 || $status >= 300) {
            // PII-safe: extract only the error_code field — never log the full body
            $errorCode = '';
            try {
                $data = $response->toArray(false);
                $errorCode = (string) ($data['error_code'] ?? '');
            } catch (\Throwable) {
                // Body may not be JSON — ignore; we already have the status code
            }
            $this->logger->critical(sprintf(
                'AxiTrace ingestion-api non-2xx: path=%s status=%d error_code=%s',
                $path,
                $status,
                $errorCode === '' ? 'n/a' : $errorCode,
            ));
            throw new IngestionUnreachableException('HTTP ' . $status);
        }
    }

    /**
     * The purchase without any per-line `unitCost`: what may travel on a request
     * that is not authenticated with the secret key.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function withoutUnitCosts(array $payload): array
    {
        if (!isset($payload['data']['products']) || !is_array($payload['data']['products'])) {
            return $payload;
        }

        foreach ($payload['data']['products'] as $i => $product) {
            if (is_array($product)) {
                unset($payload['data']['products'][$i]['unitCost']);
            }
        }

        return $payload;
    }

    private function resolveBaseUrl(?string $override): string
    {
        if ($override === null || $override === '') {
            return self::DEFAULT_API_BASE_URL;
        }

        $parts = parse_url($override);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            $this->logger->critical(
                'AxiTrace: API base URL is malformed, falling back to default'
            );
            return self::DEFAULT_API_BASE_URL;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            $this->logger->critical(
                'AxiTrace: API base URL must use https scheme, falling back to default'
            );
            return self::DEFAULT_API_BASE_URL;
        }

        $host = $parts['host'];
        $ips = gethostbynamel($host);

        if ($ips === false) {
            // Cannot resolve — treat as potentially unsafe, fall back to default
            $this->logger->critical(
                'AxiTrace: API base URL host could not be resolved, falling back to default'
            );
            return self::DEFAULT_API_BASE_URL;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                $this->logger->critical(
                    'AxiTrace: API base URL resolves to a private/reserved IP (SSRF guard), falling back to default'
                );
                return self::DEFAULT_API_BASE_URL;
            }
        }

        // Strip trailing slash for consistent URL construction
        return rtrim($override, '/');
    }

    /**
     * Returns true when the IP is private, loopback, link-local, or otherwise reserved.
     * Uses PHP's built-in FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE:
     * a return value of false from filter_var means the IP failed those flags → it IS restricted.
     */
    private function isPrivateOrReservedIp(string $ip): bool
    {
        // IPv6 loopback (::1) and unique-local (fc00::/7) are not covered by FILTER_FLAG_NO_PRIV_RANGE
        // for all PHP builds — check explicitly.
        if ($ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        $filtered = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        // filter_var returns false when the IP is private/reserved → it IS restricted
        return $filtered === false;
    }
}
