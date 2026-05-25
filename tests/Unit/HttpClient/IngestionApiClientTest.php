<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\HttpClient;

use AxitraceShopware6\Exception\IngestionUnreachableException;
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

        self::assertSame(2, $capturedTimeout);
        self::assertSame(2, $capturedMaxDuration);
    }
}
