<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\HttpClient;

use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\Exception\SecretKeyRejectedException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IngestionApiClientTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeClient(MockHttpClient $http, ?string $override = null): IngestionApiClient
    {
        return new IngestionApiClient($http, $this->logger, $override);
    }

    // -------------------------------------------------------------------------
    // Test 1: Successful 202 — no critical log
    // -------------------------------------------------------------------------

    public function testSuccessful202DoesNotLog(): void
    {
        $this->logger->expects(self::never())->method('critical');

        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 202])]);
        $client = $this->makeClient($mock);
        $client->sendEvent(['event' => 'PageView']);
    }

    // -------------------------------------------------------------------------
    // Test 2: Non-2xx logs status and error_code — PII-safe (no 'email')
    // -------------------------------------------------------------------------

    public function testNon2xxLogsStatusAndErrorCode(): void
    {
        $loggedMessage = '';
        $this->logger->expects(self::once())
            ->method('critical')
            ->with(self::callback(static function (string $message) use (&$loggedMessage): bool {
                $loggedMessage = $message;
                self::assertStringContainsString('status=400', $message);
                self::assertStringContainsString('error_code=bad_request', $message);
                self::assertStringNotContainsString('email', $message);
                return true;
            }));

        $body = json_encode(['error_code' => 'bad_request', 'detail' => 'email field invalid']);
        $mock = new MockHttpClient([new MockResponse((string) $body, ['http_code' => 400])]);
        $client = $this->makeClient($mock);

        $this->expectException(IngestionUnreachableException::class);
        $client->sendEvent(['email' => 'user@example.com']);
    }

    // -------------------------------------------------------------------------
    // Test 3: Non-2xx logs do not contain 'phone'
    // -------------------------------------------------------------------------

    public function testNon2xxLogDoesNotContainPhone(): void
    {
        $this->logger->expects(self::once())
            ->method('critical')
            ->with(self::callback(static function (string $message): bool {
                self::assertStringNotContainsString('phone', $message);
                return true;
            }));

        $body = json_encode(['error' => 'phone=12345 is invalid']);
        $mock = new MockHttpClient([new MockResponse((string) $body, ['http_code' => 422])]);
        $client = $this->makeClient($mock);

        $this->expectException(IngestionUnreachableException::class);
        $client->sendEvent(['phone' => '12345']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Transport failure logs class= prefix but not the PII URL
    // -------------------------------------------------------------------------

    public function testTransportFailureLogsClassOnly(): void
    {
        $piiUrl = 'https://stat.axitrace.com/shopware/pixel?email=secret@example.com';

        $this->logger->expects(self::once())
            ->method('critical')
            ->with(self::callback(static function (string $message) use ($piiUrl): bool {
                self::assertStringContainsString('class=', $message);
                self::assertStringNotContainsString($piiUrl, $message);
                self::assertStringNotContainsString('secret@example.com', $message);
                return true;
            }));

        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($piiUrl): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(
                'Could not reach ' . $piiUrl . ': connection refused'
            );
        });
        $client = $this->makeClient($mock);

        $this->expectException(IngestionUnreachableException::class);
        $client->sendEvent(['event' => 'PageView']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Transport failure throws IngestionUnreachableException
    // -------------------------------------------------------------------------

    public function testThrowsIngestionUnreachableOnTransportFailure(): void
    {
        $this->logger->method('critical');

        $mock = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('timeout');
        });
        $client = $this->makeClient($mock);

        $this->expectException(IngestionUnreachableException::class);
        $client->sendEvent(['event' => 'PageView']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Non-2xx throws IngestionUnreachableException
    // -------------------------------------------------------------------------

    public function testThrowsIngestionUnreachableOnNon2xx(): void
    {
        $this->logger->method('critical');

        $mock = new MockHttpClient([new MockResponse('{}', ['http_code' => 503])]);
        $client = $this->makeClient($mock);

        $this->expectException(IngestionUnreachableException::class);
        $client->sendEvent(['event' => 'PageView']);
    }

    // -------------------------------------------------------------------------
    // Test 7: Request method is POST and JSON body matches payload
    // -------------------------------------------------------------------------

    public function testRequestSendsJsonBody(): void
    {
        $this->logger->expects(self::never())->method('critical');

        $capturedBody = '';
        $capturedMethod = '';

        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody, &$capturedMethod): MockResponse {
            $capturedMethod = $method;
            $capturedBody = $options['body'] ?? '';
            return new MockResponse('', ['http_code' => 200]);
        });

        $payload = ['event' => 'AddToCart', 'value' => 29.99];
        $client = $this->makeClient($mock);
        $client->sendEvent($payload);

        self::assertSame('POST', $capturedMethod);
        $decoded = json_decode($capturedBody, true);
        self::assertSame('AddToCart', $decoded['event']);
        self::assertSame(29.99, $decoded['value']);
    }

    // -------------------------------------------------------------------------
    // Test 8: SSRF — link-local IP (169.254.x.x) is rejected at construction
    // -------------------------------------------------------------------------

    public function testSsrfRejectsLinkLocalIp(): void
    {
        // 169.254.169.254 is the EC2 metadata endpoint — a classic SSRF target.
        // Because gethostbynamel() resolves the literal IP address string,
        // the constructor should detect it as link-local and critical-log + fall back.
        $this->logger->expects(self::atLeastOnce())
            ->method('critical')
            ->with(self::stringContains('SSRF'));

        // We pass the raw IP as URL so gethostbynamel returns it directly
        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 200])]);
        // The constructor logs critical and uses DEFAULT_API_BASE_URL instead
        $client = new IngestionApiClient($mock, $this->logger, 'https://169.254.169.254/');

        // The actual request should go to the default URL (stat.axitrace.com), not the injected override
        $capturedUrl = '';
        $safeMock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;
            return new MockResponse('', ['http_code' => 200]);
        });
        $safeClient = new IngestionApiClient($safeMock, $this->logger, 'https://169.254.169.254/');
        // Send event to confirm the baseUrl is the default, not 169.254.169.254
        $safeClient->sendEvent(['event' => 'PageView']);

        self::assertStringContainsString('stat.axitrace.com', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Test 9: SSRF — http (not https) scheme is rejected at construction
    // -------------------------------------------------------------------------

    public function testSsrfRejectsHttpScheme(): void
    {
        $this->logger->expects(self::atLeastOnce())
            ->method('critical')
            ->with(self::stringContains('https scheme'));

        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 200])]);
        // Constructor should critical-log and fall back to default
        new IngestionApiClient($mock, $this->logger, 'http://stat.axitrace.com/');
    }

    // -------------------------------------------------------------------------
    // Test 10: null override uses default URL
    // -------------------------------------------------------------------------

    public function testNullOverrideUsesDefault(): void
    {
        $this->logger->expects(self::never())->method('critical');

        $capturedUrl = '';
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;
            return new MockResponse('', ['http_code' => 200]);
        });

        $client = $this->makeClient($mock, null);
        $client->sendEvent(['event' => 'PageView']);

        self::assertStringStartsWith('https://stat.axitrace.com', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Test 11: Custom timeouts (timeout=2, max_duration=2) are passed through
    // -------------------------------------------------------------------------

    public function testCustomTimeoutsArePassedToHttpClient(): void
    {
        $this->logger->expects(self::never())->method('critical');

        $capturedTimeout = null;
        $capturedMaxDuration = null;

        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedTimeout, &$capturedMaxDuration): MockResponse {
            $capturedTimeout = $options['timeout'] ?? null;
            $capturedMaxDuration = $options['max_duration'] ?? null;
            return new MockResponse('', ['http_code' => 200]);
        });

        $client = $this->makeClient($mock);
        $client->sendEvent(['event' => 'PageView']);

        // Symfony's HttpClient normalises numeric options to float — assert the
        // value, not the int/float distinction.
        self::assertSame(2.0, (float) $capturedTimeout);
        self::assertSame(2.0, (float) $capturedMaxDuration);
    }

    // -------------------------------------------------------------------------
    // Secret key: Basic header and the refund endpoint (0.5.0)
    // -------------------------------------------------------------------------

    public function testSecretKeyIsSentAsBasicAuthorization(): void
    {
        $captured = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'headers' => $options['normalized_headers'] ?? []];

            return new MockResponse('', ['http_code' => 202]);
        });

        $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge'], 'sk_live_abc123abc123abc123');

        self::assertSame('https://stat.axitrace.com/shopware/pixel', $captured['url']);
        self::assertSame(
            ['Authorization: Basic ' . base64_encode('sk_live_abc123abc123abc123:')],
            $captured['headers']['authorization'] ?? null,
        );
    }

    public function testNoAuthorizationHeaderWithoutSecretKey(): void
    {
        $captured = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['normalized_headers'] ?? [];

            return new MockResponse('', ['http_code' => 202]);
        });

        $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge']);

        self::assertArrayNotHasKey('authorization', $captured);
    }

    public function testRefundGoesToTheRefundEndpointWithTheSecretKey(): void
    {
        $captured = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'] ?? [], 'body' => $options['body'] ?? ''];

            return new MockResponse('', ['http_code' => 202]);
        });

        $this->makeClient($mock)->sendRefund(['orderId' => 'o-1', 'refundId' => 'r-1'], 'sk_live_abc123abc123abc123');

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://stat.axitrace.com/v1/refund', $captured['url']);
        self::assertSame(
            ['Authorization: Basic ' . base64_encode('sk_live_abc123abc123abc123:')],
            $captured['headers']['authorization'] ?? null,
        );
        self::assertSame(['orderId' => 'o-1', 'refundId' => 'r-1'], json_decode((string) $captured['body'], true));
    }

    public function testRefundWithoutSecretKeyIsRefusedBeforeAnyRequest(): void
    {
        $requests = 0;
        $mock = new MockHttpClient(static function () use (&$requests): MockResponse {
            $requests++;

            return new MockResponse('', ['http_code' => 202]);
        });

        try {
            $this->makeClient($mock)->sendRefund(['refundId' => 'r-1'], '');
            self::fail('An empty secret key must be refused.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, $requests);
    }

    public function testRejectedSecretKeyOnARefundIsLoggedWithoutTheKeyAndNeverResent(): void
    {
        $this->logger->expects(self::once())->method('critical')->with(self::callback(
            static fn (string $m): bool => str_contains($m, 'path=/v1/refund') && str_contains($m, 'rejected (HTTP 401)') && !str_contains($m, 'sk_live'),
        ));

        $requests = 0;
        $mock = new MockHttpClient(static function () use (&$requests): MockResponse {
            $requests++;

            return new MockResponse('{"error_code":"unauthorized"}', ['http_code' => 401]);
        });

        try {
            $this->makeClient($mock)->sendRefund(['refundId' => 'r-1'], 'sk_live_abc123abc123abc123');
            self::fail('A rejected key must surface as SecretKeyRejectedException.');
        } catch (SecretKeyRejectedException) {
        }

        self::assertSame(1, $requests);
    }

    public function testRejectedSecretKeyOnAPurchaseResendsItOnceWithoutKeyAndCosts(): void
    {
        $this->logger->expects(self::once())->method('critical')->with(self::callback(
            static fn (string $m): bool => str_contains($m, 'path=/shopware/pixel') && str_contains($m, 'rejected (HTTP 401)') && !str_contains($m, 'sk_live'),
        ));

        $requests = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['headers' => $options['normalized_headers'] ?? [], 'body' => json_decode((string) ($options['body'] ?? '{}'), true)];

            return new MockResponse('', ['http_code' => count($requests) === 1 ? 401 : 202]);
        });

        $this->makeClient($mock)->sendEvent([
            'event' => 'transaction.charge',
            'data'  => ['products' => [['sku' => 'A', 'externalId' => 'shopware:p1', 'unitCost' => ['amount' => 5.0, 'currency' => 'EUR']]]],
        ], 'sk_live_abc123abc123abc123');

        self::assertCount(2, $requests);
        self::assertArrayHasKey('authorization', $requests[0]['headers']);
        self::assertArrayNotHasKey('authorization', $requests[1]['headers']);
        self::assertSame([['sku' => 'A', 'externalId' => 'shopware:p1']], $requests[1]['body']['data']['products']);
    }

    public function testPurchaseWhoseKeylessResendFailsIsReportedUnreachable(): void
    {
        $requests = 0;
        $mock = new MockHttpClient(static function () use (&$requests): MockResponse {
            $requests++;

            return new MockResponse('', ['http_code' => $requests === 1 ? 401 : 503]);
        });

        $this->expectException(IngestionUnreachableException::class);
        try {
            $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge'], 'sk_live_abc123abc123abc123');
        } finally {
            self::assertSame(2, $requests, 'exactly one keyless resend, never a loop');
        }
    }

    public function testA401WithoutAKeyIsAnOrdinaryFailure(): void
    {
        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 401])]);

        $this->expectException(IngestionUnreachableException::class);
        $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge']);
    }

    // -------------------------------------------------------------------------
    // 0.5.3: a 202 that says the secret key did not verify (costs dropped)
    // -------------------------------------------------------------------------

    public function testUnverifiedCostKeyHeaderIsLoggedWithoutTheKeyAndWithoutAResend(): void
    {
        $secret = 'sk_live_' . str_repeat('ab', 20);
        $calls = 0;
        $mock = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"success":true}', ['http_code' => 202, 'response_headers' => ['X-AxiTrace-Cost-Key: unverified']]);
        });

        $logged = [];
        $this->logger->method('critical')->willReturnCallback(static function (string $message) use (&$logged): void {
            $logged[] = $message;
        });

        $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge'], $secret);

        self::assertSame(1, $calls, 'the purchase was accepted, it must not be sent again');
        self::assertCount(1, $logged);
        self::assertStringContainsString('not a valid AxiTrace secret key', $logged[0]);
        self::assertStringNotContainsString($secret, $logged[0]);
        self::assertStringNotContainsString('sk_live_', $logged[0]);
    }

    public function testUnverifiedCostKeyHeaderIsIgnoredWithoutASecretKey(): void
    {
        $this->logger->expects(self::never())->method('critical');

        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 202, 'response_headers' => ['X-AxiTrace-Cost-Key: unverified']])]);
        $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge']);
    }

    public function testVerifiedSecretKeyDoesNotLog(): void
    {
        $this->logger->expects(self::never())->method('critical');

        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 202])]);
        $this->makeClient($mock)->sendEvent(['event' => 'transaction.charge'], 'sk_live_' . str_repeat('cd', 20));
    }
}
