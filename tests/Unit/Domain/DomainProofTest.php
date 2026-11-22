<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Domain;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Domain\DomainProof;
use AxitraceShopware6\Storefront\DomainProofController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;

/**
 * The proof must be exactly what AxiTrace's StoreDomainProofFetcher accepts:
 * the four keys (plus the signature with a secret key), the nonce echoed, the
 * platform "shopware" and HMAC-SHA256(secret, nonce|host|public_key).
 */
final class DomainProofTest extends TestCase
{
    private const PK = 'pk_live_0123456789abcdef0123456789abcdef';
    private const SK = 'sk_live_abcdefghijklmnop1234';
    private const NONCE = 'AbCdEf0123456789_-xyz';

    public function testAnswerWithoutSecretKeyHasExactlyTheFourKeys(): void
    {
        $answer = DomainProof::answer(self::NONCE, 'shop.example', self::PK, '');

        self::assertSame(200, $answer['status']);
        self::assertSame(
            ['axitrace_domain_proof' => 1, 'public_key' => self::PK, 'nonce' => self::NONCE, 'platform' => 'shopware'],
            $answer['body'],
        );
    }

    public function testSignatureCoversNonceHostAndPublicKeyWithTheNormalizedHost(): void
    {
        $answer = DomainProof::answer(self::NONCE, 'Shop.Example:8443', self::PK, self::SK);

        self::assertSame(200, $answer['status']);
        self::assertSame(['axitrace_domain_proof', 'public_key', 'nonce', 'platform', 'signature'], array_keys($answer['body']));
        self::assertSame(hash_hmac('sha256', self::NONCE . '|shop.example|' . self::PK, self::SK), $answer['body']['signature']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNonces(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc123'];
        yield 'bad characters' => ['abcdefghijklmnop<script>'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    /**
     * @dataProvider badNonces
     */
    public function testMalformedNonceIs400(string $nonce): void
    {
        self::assertSame(['status' => 400, 'body' => ['error' => 'invalid_nonce']], DomainProof::answer($nonce, 'shop.example', self::PK, self::SK));
    }

    public function testNoPublicKeyIs404(): void
    {
        self::assertSame(['status' => 404, 'body' => ['error' => 'not_configured']], DomainProof::answer(self::NONCE, 'shop.example', '', ''));
    }

    public function testNormalizeHost(): void
    {
        self::assertSame('www.shop.example', DomainProof::normalizeHost('https://WWW.Shop.example:8443/de/'));
        self::assertSame('shop.example', DomainProof::normalizeHost('shop.example.'));
        self::assertSame('', DomainProof::normalizeHost('localhost'));
        self::assertSame('', DomainProof::normalizeHost('https://'));
    }

    public function testRouteIsDeclaredUncachedAndAllowedInMaintenance(): void
    {
        $route = (new \ReflectionMethod(DomainProofController::class, 'proof'))->getAttributes(Route::class)[0]->newInstance();

        self::assertSame('/axitrace/domain-proof', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertTrue($route->getDefaults()[PlatformRequest::ATTRIBUTE_NO_STORE]);
        self::assertFalse($route->getDefaults()[PlatformRequest::ATTRIBUTE_HTTP_CACHE]);
        self::assertTrue($route->getDefaults()[PlatformRequest::ATTRIBUTE_IS_ALLOWED_IN_MAINTENANCE]);
        self::assertArrayNotHasKey(PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED, $route->getDefaults());
    }

    public function testPluginRoutesFileLoadsTheProofRoute(): void
    {
        $configDir = realpath(__DIR__ . '/../../../src/Resources/config');
        self::assertIsString($configDir);
        $locator = new FileLocator([$configDir]);
        $routes = (new DelegatingLoader(new LoaderResolver([
            new YamlFileLoader($locator),
            new AttributeFileLoader($locator, new AttributeRouteControllerLoader()),
        ])))->load('routes.yaml');

        self::assertSame('/axitrace/domain-proof', $routes->get('frontend.axitrace.domain-proof')?->getPath());
    }

    public function testControllerAnswersWithTheKeysOfTheRequestedSalesChannel(): void
    {
        $response = $this->controller(['channel-a' => [self::PK, self::SK]])
            ->proof(Request::create('https://shop.example/axitrace/domain-proof?nonce=' . self::NONCE), $this->context('channel-a'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $body = json_decode((string) $response->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        self::assertSame(self::PK, $body['public_key']);
        self::assertSame(hash_hmac('sha256', self::NONCE . '|shop.example|' . self::PK, self::SK), $body['signature']);
    }

    public function testControllerWithoutKeyIs404AndArrayNonceIs400(): void
    {
        $controller = $this->controller(['channel-a' => [self::PK, '']]);

        $notConfigured = $controller->proof(Request::create('https://other.example/axitrace/domain-proof?nonce=' . self::NONCE), $this->context('channel-b'));
        self::assertSame(404, $notConfigured->getStatusCode());
        self::assertSame('{"error":"not_configured"}', $notConfigured->getContent());

        $arrayNonce = $controller->proof(Request::create('https://shop.example/axitrace/domain-proof?nonce[]=' . self::NONCE), $this->context('channel-a'));
        self::assertSame(400, $arrayNonce->getStatusCode());
    }

    /**
     * @param array<string, array{string, string}> $keysByChannel
     */
    private function controller(array $keysByChannel): DomainProofController
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('get')->willReturnCallback(static fn (string $key, ?string $channel = null) => match ($key) {
            'AxitraceShopware6.config.publicKey' => $keysByChannel[$channel][0] ?? '',
            'AxitraceShopware6.config.secretKey' => $keysByChannel[$channel][1] ?? '',
            default => null,
        });

        return new DomainProofController(new PluginConfig($configService, new AxitraceCrypto('secret'), new NullLogger()));
    }

    private function context(string $salesChannelId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        return $context;
    }
}
