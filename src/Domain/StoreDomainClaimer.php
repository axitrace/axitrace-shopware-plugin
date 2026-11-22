<?php

declare(strict_types=1);

namespace AxitraceShopware6\Domain;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\PluginVersion;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks AxiTrace to verify the shop's storefront domains, so their browser events
 * are accepted without the merchant adding or verifying each domain by hand.
 *
 * AxiTrace accepts browser events only from verified domains of the workspace.
 * Hosts: every domain URL of an active storefront Sales Channel that has a
 * public key (its own or inherited), grouped by the keys of that channel, one
 * claim per group of up to 10 hosts:
 * POST https://axitrace.com/api/public/store-domains/claim with the public key,
 * `platform: shopware` and the hosts (plus the secret key as HTTP Basic when
 * the channel has one). AxiTrace then fetches /axitrace/domain-proof on each
 * host (DomainProofController). The answer per host (verified, pending, failed,
 * rejected + reason) is stored for the settings page.
 *
 * Runs after the settings are saved, on activation and update, and once a day
 * (AxitraceClaimStoreDomainTask). The claim always goes to axitrace.com, never
 * to the ingestion host.
 */
final class StoreDomainClaimer
{
    public const DEFAULT_BASE_URL = 'https://axitrace.com';
    public const CLAIM_PATH = '/api/public/store-domains/claim';
    private const MAX_HOSTS_PER_CLAIM = 10;

    private readonly string $baseUrl;

    /**
     * @param string|null $baseUrlOverride AXITRACE_ADMIN_BASE_URL, scheme + host only
     *                                     (local end-to-end runs; empty in shops)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $salesChannelDomainRepository,
        private readonly PluginConfig $config,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        ?string $baseUrlOverride = null,
    ) {
        $override = rtrim(trim((string) $baseUrlOverride), '/');
        $this->baseUrl = preg_match('~^https?://[^/?#@\s]+$~', $override) === 1 ? $override : self::DEFAULT_BASE_URL;
    }

    /**
     * Claims every storefront host now and stores the outcome.
     *
     * @return array{checked_at: string, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}
     */
    public function claimAll(): array
    {
        $groups = $this->hostGroups();
        if ($groups === []) {
            return $this->store([], 'no_public_key');
        }

        $previous = [];
        foreach ($this->config->getStoreDomainStatus()['hosts'] ?? [] as $entry) {
            $previous[$entry['host']] = $entry;
        }

        $hosts = [];
        $error = null;
        foreach ($groups as $group) {
            foreach (array_chunk($group['hosts'], self::MAX_HOSTS_PER_CLAIM) as $chunk) {
                $result = $this->claim($group['publicKey'], $group['secretKey'], $chunk);
                if (\is_string($result)) {
                    $error ??= $result;
                    foreach ($chunk as $host) {
                        if (isset($previous[$host])) {
                            $hosts[] = $previous[$host];
                        }
                    }
                    continue;
                }
                array_push($hosts, ...$result);
            }
        }

        return $this->store($hosts, $error);
    }

    /**
     * Storefront hosts grouped by the keys of their Sales Channel.
     *
     * @return list<array{publicKey: string, secretKey: string, hosts: list<string>}>
     */
    public function hostGroups(): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('salesChannel.typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT))
            ->addFilter(new EqualsFilter('salesChannel.active', true));

        $groups = [];
        $keysByChannel = [];
        foreach ($this->salesChannelDomainRepository->search($criteria, Context::createCLIContext())->getEntities() as $domain) {
            if (!$domain instanceof SalesChannelDomainEntity) {
                continue;
            }
            $host = DomainProof::normalizeHost($domain->getUrl());
            $channelId = $domain->getSalesChannelId();
            if ($host === '') {
                continue;
            }

            $keysByChannel[$channelId] ??= [
                'publicKey' => $this->config->getPublicKey($channelId),
                'secretKey' => $this->config->getSecretKey($channelId),
            ];
            ['publicKey' => $publicKey, 'secretKey' => $secretKey] = $keysByChannel[$channelId];
            if ($publicKey === '') {
                continue;
            }

            $groupKey = $publicKey . "\n" . $secretKey;
            $groups[$groupKey] ??= ['publicKey' => $publicKey, 'secretKey' => $secretKey, 'hosts' => []];
            if (!\in_array($host, $groups[$groupKey]['hosts'], true)) {
                $groups[$groupKey]['hosts'][] = $host;
            }
        }

        return array_values($groups);
    }

    /**
     * @param list<string> $hosts
     *
     * @return list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>|string the hosts, or an error code
     */
    private function claim(string $publicKey, string $secretKey, array $hosts): array|string
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'AxiTrace-Shopware/' . PluginVersion::VERSION,
        ];
        if ($secretKey !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($secretKey . ':');
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . self::CLAIM_PATH, [
                'headers'       => $headers,
                'body'          => json_encode(
                    ['public_key' => $publicKey, 'platform' => DomainProof::PLATFORM, 'hosts' => $hosts],
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
                ),
                'timeout'       => 10,
                'max_duration'  => 20,
                'max_redirects' => 0,
            ]);
            $code = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->error('AxiTrace store domain claim: AxiTrace could not be reached: ' . $e::class . ': ' . $e->getMessage());

            return 'unreachable';
        }

        try {
            $json = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }

        if ($code !== 200 || !\is_array($json) || ($json['success'] ?? false) !== true || !\is_array($json['hosts'] ?? null)) {
            $error = \is_array($json) && \is_string($json['error'] ?? null) ? $json['error'] : 'http_' . $code;
            $this->logger->critical(sprintf('AxiTrace store domain claim refused by AxiTrace: HTTP %d %s', $code, $error), ['hosts' => $hosts]);

            return $error;
        }

        return self::hostsOf($json['hosts']);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>
     */
    private static function hostsOf(array $raw): array
    {
        $hosts = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry) || !\is_string($entry['host'] ?? null) || !\is_string($entry['status'] ?? null)) {
                continue;
            }
            $hosts[] = [
                'host'                    => $entry['host'],
                'status'                  => $entry['status'],
                'reason'                  => \is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
                'browser_events_rejected' => ($entry['browser_events_rejected'] ?? false) === true,
            ];
        }

        return $hosts;
    }

    /**
     * @param list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}> $hosts
     *
     * @return array{checked_at: string, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}
     */
    private function store(array $hosts, ?string $error): array
    {
        $status = [
            'checked_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
            'hosts'      => $hosts,
            'error'      => $error,
        ];
        $this->config->setStoreDomainStatus($status, StoreDomainStatusMessage::describe($status));

        return $status;
    }
}
