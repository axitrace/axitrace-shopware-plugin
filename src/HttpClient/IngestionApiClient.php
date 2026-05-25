<?php

declare(strict_types=1);

namespace AxitraceShopware6\HttpClient;

use AxitraceShopware6\Exception\IngestionUnreachableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IngestionApiClient
{
    public const DEFAULT_API_BASE_URL = 'https://stat.axitrace.com';
    public const ENDPOINT_PATH = '/shopware/pixel';
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

    public function sendEvent(array $payload): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . self::ENDPOINT_PATH, [
                'json'         => $payload,
                'timeout'      => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'headers'      => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            // PII-safe: log only exception class name, NOT message (URL may contain query params with PII)
            $this->logger->critical('AxiTrace ingestion-api transport failure: class=' . $e::class);
            throw new IngestionUnreachableException('transport error', 0, $e);
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
                'AxiTrace ingestion-api non-2xx: status=%d error_code=%s',
                $status,
                $errorCode === '' ? 'n/a' : $errorCode,
            ));
            throw new IngestionUnreachableException('HTTP ' . $status);
        }
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
