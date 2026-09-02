<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Config;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Consent\ConsentMode;
use AxitraceShopware6\Normalizer\ConversionValueBasis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Unit tests for PluginConfig per-Sales-Channel settings facade.
 *
 * SystemConfigService and LoggerInterface are mocked so these tests run
 * without a Shopware container or database.
 */
final class PluginConfigTest extends TestCase
{
    private const APP_SECRET = 'test-app-secret-fixture';
    private const VALID_PK = 'pk_live_abcdef1234567890abcdef1234567890';

    private SystemConfigService&MockObject $configService;
    private LoggerInterface&MockObject $logger;
    private AxitraceCrypto $crypto;
    private PluginConfig $pluginConfig;

    protected function setUp(): void
    {
        $this->configService = $this->createMock(SystemConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->crypto = new AxitraceCrypto(self::APP_SECRET);

        $this->pluginConfig = new PluginConfig(
            $this->configService,
            $this->crypto,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------------
    // getPublicKey
    // -------------------------------------------------------------------------

    public function testGetPublicKeyReturnsDecryptedPlaintext(): void
    {
        $ciphertext = $this->crypto->encrypt(self::VALID_PK);

        $this->configService
            ->method('get')
            ->with('AxitraceShopware6.config.publicKey', null)
            ->willReturn($ciphertext);

        self::assertSame(self::VALID_PK, $this->pluginConfig->getPublicKey());
    }

    public function testGetPublicKeyEmptyWhenUnconfigured(): void
    {
        $this->configService
            ->method('get')
            ->willReturn(null);

        self::assertSame('', $this->pluginConfig->getPublicKey());
    }

    public function testGetPublicKeyInvalidFormatLogsCritical(): void
    {
        // Encrypt a malformed value that passes decryption but fails format check
        $malformed = $this->crypto->encrypt('not-a-valid-key');

        $this->configService
            ->method('get')
            ->with('AxitraceShopware6.config.publicKey', null)
            ->willReturn($malformed);

        $this->logger
            ->expects(self::once())
            ->method('critical')
            ->with(
                self::stringContains('publicKey failed format validation'),
                self::isType('array'),
            );

        self::assertSame('', $this->pluginConfig->getPublicKey());
    }

    // -------------------------------------------------------------------------
    // setPublicKey
    // -------------------------------------------------------------------------

    public function testSetPublicKeyEncryptsBeforeStore(): void
    {
        $capturedValue = null;

        $this->configService
            ->expects(self::once())
            ->method('set')
            ->with(
                'AxitraceShopware6.config.publicKey',
                self::callback(static function (string $v) use (&$capturedValue): bool {
                    $capturedValue = $v;

                    return true;
                }),
                null,
            );

        $this->pluginConfig->setPublicKey(self::VALID_PK);

        // The stored value must NOT be the plaintext key
        self::assertNotSame(self::VALID_PK, $capturedValue);

        // But it must decrypt back to the original
        self::assertSame(self::VALID_PK, $this->crypto->decrypt((string) $capturedValue));
    }

    public function testSetPublicKeyRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pluginConfig->setPublicKey('bad-key-value');
    }

    // -------------------------------------------------------------------------
    // isEnabled
    // -------------------------------------------------------------------------

    public function testIsEnabledFalseWhenNoPublicKey(): void
    {
        $this->configService
            ->method('get')
            ->willReturn(null);

        self::assertFalse($this->pluginConfig->isEnabled());
    }

    public function testIsEnabledRespectsExplicitDisable(): void
    {
        $this->configService
            ->method('get')
            ->willReturnCallback(function (string $key, ?string $channelId): mixed {
                if ($key === 'AxitraceShopware6.config.enabled') {
                    return false;
                }

                return null;
            });

        self::assertFalse($this->pluginConfig->isEnabled());
    }

    public function testIsEnabledTrueWhenKeyConfiguredAndFlagSet(): void
    {
        $ciphertext = $this->crypto->encrypt(self::VALID_PK);

        $this->configService
            ->method('get')
            ->willReturnCallback(function (string $key, ?string $channelId) use ($ciphertext): mixed {
                return match ($key) {
                    'AxitraceShopware6.config.enabled' => true,
                    'AxitraceShopware6.config.publicKey' => $ciphertext,
                    default => null,
                };
            });

        self::assertTrue($this->pluginConfig->isEnabled());
    }

    // -------------------------------------------------------------------------
    // Per-Sales-Channel isolation
    // -------------------------------------------------------------------------

    public function testPerSalesChannelIsolation(): void
    {
        $channelA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $channelB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $pkA = 'pk_live_aaaa5678901234567890123456789012';
        $pkB = 'pk_live_bbbb5678901234567890123456789012';

        $encA = $this->crypto->encrypt($pkA);
        $encB = $this->crypto->encrypt($pkB);

        $this->configService
            ->method('get')
            ->willReturnCallback(
                function (string $key, ?string $channelId) use ($channelA, $channelB, $encA, $encB): mixed {
                    if ($key !== 'AxitraceShopware6.config.publicKey') {
                        return null;
                    }

                    return match ($channelId) {
                        $channelA => $encA,
                        $channelB => $encB,
                        default => null,
                    };
                },
            );

        self::assertSame($pkA, $this->pluginConfig->getPublicKey($channelA));
        self::assertSame($pkB, $this->pluginConfig->getPublicKey($channelB));
    }

    // -------------------------------------------------------------------------
    // Failure counters
    // -------------------------------------------------------------------------

    public function testFailureCountersRoundTrip(): void
    {
        $store = [];

        $this->configService
            ->method('get')
            ->willReturnCallback(
                static function (string $key, ?string $channelId) use (&$store): mixed {
                    return $store[$key] ?? null;
                },
            );

        $this->configService
            ->method('set')
            ->willReturnCallback(
                static function (string $key, mixed $value, ?string $channelId) use (&$store): void {
                    $store[$key] = $value;
                },
            );

        $this->pluginConfig->recordFailure('Connection refused', null);

        self::assertSame(1, $this->pluginConfig->getRecentFailureCount());
        self::assertNotNull($this->pluginConfig->getLastFailureAt());
        self::assertSame('Connection refused', $this->pluginConfig->getLastFailureMessage());

        // Record a second failure
        $this->pluginConfig->recordFailure('Timeout', null);

        self::assertSame(2, $this->pluginConfig->getRecentFailureCount());
        self::assertSame('Timeout', $this->pluginConfig->getLastFailureMessage());

        // Clear
        $this->pluginConfig->clearFailureCounters();

        self::assertSame(0, $this->pluginConfig->getRecentFailureCount());
        self::assertNull($this->pluginConfig->getLastFailureAt());
        self::assertNull($this->pluginConfig->getLastFailureMessage());
    }

    public function testRecordFailureTruncatesLongMessage(): void
    {
        $store = [];

        $this->configService
            ->method('get')
            ->willReturnCallback(static fn (string $k): mixed => $store[$k] ?? null);

        $this->configService
            ->method('set')
            ->willReturnCallback(static function (string $k, mixed $v) use (&$store): void {
                $store[$k] = $v;
            });

        $longMessage = str_repeat('x', 600);

        $this->pluginConfig->recordFailure($longMessage);

        $stored = $store['AxitraceShopware6.runtime.last_failure_message'] ?? '';
        self::assertSame(500, strlen((string) $stored));
    }

    // -------------------------------------------------------------------------
    // Legacy plaintext fallback
    // -------------------------------------------------------------------------

    public function testConversionValueBasisDefaultsToGrossTotalWhenUnset(): void
    {
        $this->configService->method('get')
            ->with('AxitraceShopware6.config.conversionValueBasis', null)
            ->willReturn(null);

        self::assertSame(ConversionValueBasis::GrossTotal, $this->pluginConfig->getConversionValueBasis());
    }

    public function testConversionValueBasisReadsConfiguredOptionPerSalesChannel(): void
    {
        $this->configService->method('get')
            ->willReturnCallback(static fn (string $key, ?string $channel) => match ($channel) {
                'channel-a' => 'net_excl_shipping',
                'channel-b' => 'not-a-real-option',
                default => null,
            });

        self::assertSame(ConversionValueBasis::NetExclShipping, $this->pluginConfig->getConversionValueBasis('channel-a'));
        // Invalid values never break tracking — they fall back to the default.
        self::assertSame(ConversionValueBasis::GrossTotal, $this->pluginConfig->getConversionValueBasis('channel-b'));
    }

    public function testGetPublicKeyAcceptsLegacyPlaintextValue(): void
    {
        // Simulate a value stored WITHOUT encryption (v0.1.0 install)
        $this->configService
            ->method('get')
            ->with('AxitraceShopware6.config.publicKey', null)
            ->willReturn(self::VALID_PK);

        // Should return the plaintext directly without logging a critical error
        $this->logger->expects(self::never())->method('critical');

        self::assertSame(self::VALID_PK, $this->pluginConfig->getPublicKey());
    }

    // -------------------------------------------------------------------------
    // Consent mode
    // -------------------------------------------------------------------------

    public function testGetConsentModeDefaultsToOffWhenUnset(): void
    {
        // The update-path case: existing installs never saved the config form,
        // so SystemConfigService returns null. Off is the only safe answer.
        $this->configService->method('get')
            ->with('AxitraceShopware6.config.consentMode', null)
            ->willReturn(null);

        $this->logger->expects(self::never())->method('critical');

        self::assertSame(ConsentMode::Off, $this->pluginConfig->getConsentMode());
    }

    public function testGetConsentModeReadsConfiguredOptionPerSalesChannel(): void
    {
        $this->configService->method('get')
            ->willReturnCallback(static fn (string $key, ?string $channel) => match ($channel) {
                'channel-a' => 'browser',
                'channel-b' => 'all',
                'channel-c' => 'garbage-value',
                default => null,
            });

        self::assertSame(ConsentMode::Browser, $this->pluginConfig->getConsentMode('channel-a'));
        self::assertSame(ConsentMode::All, $this->pluginConfig->getConsentMode('channel-b'));
        // Invalid values never gate tracking — they fall back to Off.
        self::assertSame(ConsentMode::Off, $this->pluginConfig->getConsentMode('channel-c'));
    }

    // -------------------------------------------------------------------------
    // Consent cookie name
    // -------------------------------------------------------------------------

    public function testGetConsentCookieNameDefaultsWhenUnset(): void
    {
        foreach ([null, '', '   '] as $raw) {
            $this->configService->method('get')->willReturn($raw);

            self::assertSame(
                ConsentGate::DEFAULT_CONSENT_COOKIE,
                $this->pluginConfig->getConsentCookieName(),
                'Input ' . var_export($raw, true) . ' must fall back to the default cookie',
            );
        }
    }

    public function testGetConsentCookieNameReturnsTrimmedCustomName(): void
    {
        $this->configService->method('get')->willReturn('  my_cmp_ok  ');

        self::assertSame('my_cmp_ok', $this->pluginConfig->getConsentCookieName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCookieNameProvider(): iterable
    {
        yield 'space inside'       => ['a b'];
        yield 'semicolon'          => ['a;b'];
        yield 'equals sign'        => ['a=b'];
        yield '200-char overflow'  => [str_repeat('a', 200)];
    }

    #[DataProvider('invalidCookieNameProvider')]
    public function testGetConsentCookieNameInvalidInputFallsBackWithOneCriticalLog(string $raw): void
    {
        $this->configService->method('get')->willReturn($raw);

        $this->logger
            ->expects(self::once())
            ->method('critical')
            ->with(
                self::stringContains('consentCookie failed format validation'),
                self::callback(static fn (array $context): bool => isset($context['raw']) && array_key_exists('sales_channel_id', $context)),
            );

        self::assertSame(
            ConsentGate::DEFAULT_CONSENT_COOKIE,
            $this->pluginConfig->getConsentCookieName('channel-x'),
        );
    }
}
