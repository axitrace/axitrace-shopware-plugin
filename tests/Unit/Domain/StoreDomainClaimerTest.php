<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Domain;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Domain\StoreDomainClaimer;
use AxitraceShopware6\Domain\StoreDomainStatusMessage;
use AxitraceShopware6\Subscriber\StoreDomainClaimSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigMultipleChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StoreDomainClaimerTest extends TestCase
{
    private const PK_A = 'pk_live_0123456789abcdef0123456789abcdef';
    private const PK_B = 'pk_live_fedcba9876543210fedcba9876543210';
    private const SK_A = 'sk_live_abcdefghijklmnop1234';

    /** @var array<string, mixed> system_config rows, key "<key>|<channel>" */
    private array $stored = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<Criteria> */
    private array $criteria = [];

    protected function setUp(): void
    {
        $this->stored = [];
        $this->requests = [];
        $this->criteria = [];
    }

    public function testClaimsEveryStorefrontHostGroupedByTheKeysOfItsSalesChannel(): void
    {
        $claimer = $this->claimer(
            [
                ['channel-a', 'https://Shop.example'],
                ['channel-a', 'https://shop.example/de'],
                ['channel-a', 'https://www.shop.example:443/'],
                ['channel-b', 'https://shop-b.example'],
                ['channel-c', 'https://no-key.example'],
            ],
            ['channel-a' => [self::PK_A, self::SK_A], 'channel-b' => [self::PK_B, '']],
            [
                $this->ok([['host' => 'shop.example', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false],
                    ['host' => 'www.shop.example', 'status' => 'pending', 'reason' => null, 'browser_events_rejected' => false]]),
                $this->ok([['host' => 'shop-b.example', 'status' => 'failed', 'reason' => 'proof_not_found', 'browser_events_rejected' => true]]),
            ],
        );

        $status = $claimer->claimAll();

        self::assertCount(2, $this->requests);
        [$first, $second] = $this->requests;
        self::assertSame('POST', $first['method']);
        self::assertSame('https://axitrace.com/api/public/store-domains/claim', $first['url']);
        self::assertSame(
            ['public_key' => self::PK_A, 'platform' => 'shopware', 'hosts' => ['shop.example', 'www.shop.example']],
            json_decode((string) $first['options']['body'], true),
        );
        self::assertContains('Authorization: Basic ' . base64_encode(self::SK_A . ':'), $first['options']['headers']);
        self::assertSame(['public_key' => self::PK_B, 'platform' => 'shopware', 'hosts' => ['shop-b.example']], json_decode((string) $second['options']['body'], true));
        self::assertEmpty(array_filter($second['options']['headers'], static fn (string $h) => str_starts_with($h, 'Authorization')));

        self::assertSame(['shop.example', 'www.shop.example', 'shop-b.example'], array_column($status['hosts'], 'host'));
        self::assertNull($status['error']);
        self::assertSame('2026-11-22 10:00 UTC', $status['checked_at']);
        self::assertSame($status, $this->stored['AxitraceShopware6.runtime.storeDomainStatus|']);
        $text = $this->stored[PluginConfig::STORE_DOMAIN_STATUS_TEXT_KEY . '|'];
        self::assertStringContainsString('OK: shop.example is verified', $text);
        self::assertStringContainsString('still verifying www.shop.example', $text);
        self::assertStringContainsString('https://shop-b.example/axitrace/domain-proof', $text);

        $filters = array_map(static fn (EqualsFilter $f) => [$f->getField(), $f->getValue()], $this->criteria[0]->getFilters());
        self::assertContains(['salesChannel.typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT], $filters);
        self::assertContains(['salesChannel.active', true], $filters);
    }

    public function testNoChannelWithAKeyStoresNoPublicKeyWithoutCallingAxiTrace(): void
    {
        $status = $this->claimer([['channel-c', 'https://no-key.example']], [], [])->claimAll();

        self::assertSame([], $this->requests);
        self::assertSame('no_public_key', $status['error']);
        self::assertStringContainsString('Enter the public key', $this->stored[PluginConfig::STORE_DOMAIN_STATUS_TEXT_KEY . '|']);
    }

    public function testRefusedClaimKeepsThePreviousHostOutcomeAndRecordsTheError(): void
    {
        $this->stored['AxitraceShopware6.runtime.storeDomainStatus|'] = [
            'checked_at' => '2026-11-21 10:00 UTC',
            'hosts' => [['host' => 'shop.example', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false]],
            'error' => null,
        ];
        $claimer = $this->claimer(
            [['channel-a', 'https://shop.example']],
            ['channel-a' => [self::PK_A, '']],
            [new MockResponse('{"success":false,"error":"rate_limited"}', ['http_code' => 429])],
        );

        $status = $claimer->claimAll();

        self::assertSame('rate_limited', $status['error']);
        self::assertSame('verified', $status['hosts'][0]['status']);
        self::assertStringContainsString('Too many verification requests', $this->stored[PluginConfig::STORE_DOMAIN_STATUS_TEXT_KEY . '|']);
    }

    public function testUnreachableAxiTraceIsRecorded(): void
    {
        $claimer = $this->claimer(
            [['channel-a', 'https://shop.example']],
            ['channel-a' => [self::PK_A, '']],
            [static fn () => throw new TransportException('Connection refused')],
        );

        $status = $claimer->claimAll();

        self::assertSame('unreachable', $status['error']);
        self::assertSame([], $status['hosts']);
        self::assertStringContainsString('could not be reached', $this->stored[PluginConfig::STORE_DOMAIN_STATUS_TEXT_KEY . '|']);
    }

    public function testMoreThanTenHostsAreSplitIntoClaimsOfTen(): void
    {
        $domains = [];
        for ($i = 1; $i <= 12; ++$i) {
            $domains[] = ['channel-a', "https://shop{$i}.example"];
        }
        $this->claimer($domains, ['channel-a' => [self::PK_A, '']], [$this->ok([]), $this->ok([])])->claimAll();

        self::assertCount(2, $this->requests);
        self::assertCount(10, json_decode((string) $this->requests[0]['options']['body'], true)['hosts']);
        self::assertCount(2, json_decode((string) $this->requests[1]['options']['body'], true)['hosts']);
    }

    public function testSubscriberClaimsOnceAfterABatchSaveThatChangedAKey(): void
    {
        $claimer = $this->claimer([['channel-a', 'https://shop.example']], ['channel-a' => [self::PK_A, '']], [$this->ok([])]);
        $subscriber = new StoreDomainClaimSubscriber($claimer, new NullLogger());

        $subscriber->onConfigChanged(new SystemConfigChangedEvent('AxitraceShopware6.config.debugMode', true, null));
        $subscriber->onBatchSaved(new SystemConfigMultipleChangedEvent([], null));
        self::assertCount(0, $this->requests, 'An unwatched setting does not claim.');

        $subscriber->onConfigChanged(new SystemConfigChangedEvent('AxitraceShopware6.config.publicKey', self::PK_A, null));
        $subscriber->onConfigChanged(new SystemConfigChangedEvent('AxitraceShopware6.config.secretKey', '', null));
        $subscriber->onBatchSaved(new SystemConfigMultipleChangedEvent([], null));
        $subscriber->onTerminate();
        self::assertCount(1, $this->requests);
    }

    public function testEveryReasonHasAPlainSentence(): void
    {
        foreach (['staging_host', 'held_by_other_workspace', 'proof_other_workspace', 'proof_signature_invalid', 'unreachable'] as $reason) {
            $line = StoreDomainStatusMessage::problem(['host' => 'shop.example', 'status' => 'failed', 'reason' => $reason, 'browser_events_rejected' => true]);
            self::assertIsString($line);
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $line);
        }
        self::assertNull(StoreDomainStatusMessage::problem(['host' => 'shop.example', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false]));
    }

    /**
     * @param list<array{string, string}>                   $domains       [salesChannelId, url]
     * @param array<string, array{string, string}>          $keysByChannel salesChannelId => [publicKey, secretKey]
     * @param list<MockResponse|callable(): MockResponse>   $responses
     */
    private function claimer(array $domains, array $keysByChannel, array $responses): StoreDomainClaimer
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturnCallback(fn (string $key, ?string $channel = null) => match ($key) {
            'AxitraceShopware6.config.publicKey' => $keysByChannel[$channel][0] ?? '',
            'AxitraceShopware6.config.secretKey' => $keysByChannel[$channel][1] ?? '',
            default => $this->stored[$key . '|' . $channel] ?? null,
        });
        $configService->method('set')->willReturnCallback(function (string $key, mixed $value, ?string $channel = null): void {
            $this->stored[$key . '|' . $channel] = $value;
        });

        $collection = new SalesChannelDomainCollection();
        foreach ($domains as $i => [$channelId, $url]) {
            $domain = new SalesChannelDomainEntity();
            $domain->setId('domain-' . $i);
            $domain->setSalesChannelId($channelId);
            $domain->setUrl($url);
            $collection->add($domain);
        }
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria, Context $context) use ($collection): EntitySearchResult {
            $this->criteria[] = $criteria;

            return new EntitySearchResult('sales_channel_domain', $collection->count(), $collection, null, $criteria, $context);
        });

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($responses);
            self::assertNotNull($next, 'Unexpected request to ' . $url);

            return \is_callable($next) ? $next() : $next;
        });

        return new StoreDomainClaimer(
            $http,
            $repository,
            new PluginConfig($configService, new AxitraceCrypto('secret'), new NullLogger()),
            new NullLogger(),
            new MockClock('2026-11-22 10:00:00 UTC'),
        );
    }

    /**
     * @param list<array<string, mixed>> $hosts
     */
    private function ok(array $hosts): MockResponse
    {
        return new MockResponse(json_encode(['success' => true, 'hosts' => $hosts], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
