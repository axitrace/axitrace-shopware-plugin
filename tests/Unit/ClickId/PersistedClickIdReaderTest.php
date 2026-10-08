<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\ClickId;

use AxitraceShopware6\ClickId\PersistedClickIdReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The click ids the plugin persists with an order: from the request URL, else from
 * the "v2|<firstSeenMs>|<clickId>" cookies the AxiTrace web SDK writes.
 *
 * Why this exists: through 0.5.0 the plugin read none of _gclid, _gbraid, _wbraid,
 * _ttclid and _oppref, so server-side purchases lost their TikTok and Google click,
 * and it forwarded _rdt_cid raw, so Reddit received "v2|...|<id>" as the click id.
 */
final class PersistedClickIdReaderTest extends TestCase
{
    /** 2026-10-08T12:00:00Z in Unix milliseconds. */
    private const NOW_MS = 1_791_460_800_000;

    private const DAY_MS = 86_400_000;

    private PersistedClickIdReader $reader;

    protected function setUp(): void
    {
        $this->reader = new PersistedClickIdReader(static fn (): int => self::NOW_MS);
    }

    /**
     * Every click id with its cookie name, web SDK maximum age and a realistic value.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function clickIdProvider(): iterable
    {
        yield 'gclid' => ['gclid', '_gclid', 90, 'Cj0KCQjw-gclid_ABC123'];
        yield 'gbraid' => ['gbraid', '_gbraid', 90, '0AAAAADgbraid-xyz_1'];
        yield 'wbraid' => ['wbraid', '_wbraid', 90, 'CkwKCAwbraid_value-9'];
        yield 'ttclid' => ['ttclid', '_ttclid', 90, 'E.C.P.CsEBttclid-value_1'];
        yield 'rdt_cid' => ['rdt_cid', '_rdt_cid', 28, '3141592653589793238_rdt'];
        yield 'oppref' => ['oppref', '_oppref', 28, 'oppref_6a3c9e1b-77'];
    }

    public function testTheReaderCoversExactlyTheWebSdkClickIdCookies(): void
    {
        self::assertSame(
            [
                'gclid' => ['_gclid', 90],
                'gbraid' => ['_gbraid', 90],
                'wbraid' => ['_wbraid', 90],
                'ttclid' => ['_ttclid', 90],
                'rdt_cid' => ['_rdt_cid', 28],
                'oppref' => ['_oppref', 28],
            ],
            PersistedClickIdReader::CLICK_IDS,
        );
    }

    #[DataProvider('clickIdProvider')]
    public function testCookieOnlyYieldsTheBareClickId(string $param, string $cookie, int $maxAgeDays, string $clickId): void
    {
        $request = $this->request([], [$cookie => $this->cookie(self::NOW_MS - self::DAY_MS, $clickId)]);

        self::assertSame([$param => $clickId], $this->reader->read($request));
    }

    #[DataProvider('clickIdProvider')]
    public function testUrlOnlyYieldsTheClickId(string $param, string $cookie, int $maxAgeDays, string $clickId): void
    {
        self::assertSame([$param => $clickId], $this->reader->read($this->request([$param => $clickId], [])));
    }

    #[DataProvider('clickIdProvider')]
    public function testUrlWinsOverTheCookie(string $param, string $cookie, int $maxAgeDays, string $clickId): void
    {
        $request = $this->request(
            [$param => 'fresh-' . $clickId],
            [$cookie => $this->cookie(self::NOW_MS - self::DAY_MS, 'older-' . $clickId)],
        );

        self::assertSame([$param => 'fresh-' . $clickId], $this->reader->read($request));
    }

    #[DataProvider('clickIdProvider')]
    public function testCookieJustInsideItsMaximumAgeIsRead(string $param, string $cookie, int $maxAgeDays, string $clickId): void
    {
        $request = $this->request([], [$cookie => $this->cookie(self::NOW_MS - $maxAgeDays * self::DAY_MS, $clickId)]);

        self::assertSame([$param => $clickId], $this->reader->read($request));
    }

    #[DataProvider('clickIdProvider')]
    public function testExpiredCookieIsIgnored(string $param, string $cookie, int $maxAgeDays, string $clickId): void
    {
        $request = $this->request([], [$cookie => $this->cookie(self::NOW_MS - $maxAgeDays * self::DAY_MS - 1, $clickId)]);

        self::assertSame([], $this->reader->read($request));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedCookieProvider(): iterable
    {
        yield 'legacy unversioned value' => ['Cj0KCQjw-legacy'];
        yield 'other version' => ['v1|1791460000000|Cj0KCQjw'];
        yield 'missing click id' => ['v2|1791460000000|'];
        yield 'missing timestamp' => ['v2||Cj0KCQjw'];
        yield 'non-numeric timestamp' => ['v2|abc|Cj0KCQjw'];
        yield 'zero timestamp' => ['v2|0|Cj0KCQjw'];
        yield 'only two parts' => ['v2|1791460000000'];
        yield 'click id with a space' => ['v2|1791460000000|Cj0 KCQjw'];
        yield 'click id with a control character' => ["v2|1791460000000|Cj0\x01KCQjw"];
        yield 'click id over 500 characters' => ['v2|1791460000000|' . str_repeat('a', 501)];
        yield 'empty cookie' => [''];
    }

    #[DataProvider('malformedCookieProvider')]
    public function testMalformedCookieIsIgnoredForEveryClickId(string $raw): void
    {
        $cookies = [];
        foreach (PersistedClickIdReader::CLICK_IDS as [$cookieName]) {
            $cookies[$cookieName] = $raw;
        }

        self::assertSame([], $this->reader->read($this->request([], $cookies)));
    }

    public function testMalformedUrlValueFallsBackToTheCookie(): void
    {
        $request = $this->request(
            ['gclid' => 'not a click id', 'ttclid' => str_repeat('t', 501)],
            [
                '_gclid' => $this->cookie(self::NOW_MS - self::DAY_MS, 'cookie-gclid-1'),
                '_ttclid' => $this->cookie(self::NOW_MS - self::DAY_MS, 'cookie-ttclid-1'),
            ],
        );

        self::assertSame(
            ['gclid' => 'cookie-gclid-1', 'ttclid' => 'cookie-ttclid-1'],
            $this->reader->read($request),
        );
    }

    public function testArrayUrlValueIsIgnoredWithoutAnError(): void
    {
        $request = $this->request(['gclid' => ['a', 'b']], []);

        self::assertSame([], $this->reader->read($request));
    }

    /**
     * The web SDK drops a landing URL whose click id is the very click its cookie
     * already holds past the maximum age: a bookmarked or shared link, not a new click.
     */
    #[DataProvider('clickIdProvider')]
    public function testBookmarkedUrlRepeatingAnExpiredStoredClickIsDropped(string $param, string $cookie, int $maxAgeDays, string $clickId): void
    {
        $request = $this->request(
            [$param => $clickId],
            [$cookie => $this->cookie(self::NOW_MS - ($maxAgeDays + 1) * self::DAY_MS, $clickId)],
        );

        self::assertSame([], $this->reader->read($request));
    }

    public function testUrlClickDifferentFromAnExpiredStoredClickIsANewClick(): void
    {
        $request = $this->request(
            ['gclid' => 'brand-new-click'],
            ['_gclid' => $this->cookie(self::NOW_MS - 200 * self::DAY_MS, 'old-click')],
        );

        self::assertSame(['gclid' => 'brand-new-click'], $this->reader->read($request));
    }

    public function testUrlRepeatingALegacyStoredClickIsDropped(): void
    {
        $request = $this->request(['ttclid' => 'legacy-click-1'], ['_ttclid' => 'legacy-click-1']);

        self::assertSame([], $this->reader->read($request));
    }

    public function testRedditClickIdIsReducedToTheBareIdNotTheCookieFormat(): void
    {
        $raw = 'v2|1791400000000|t2_reddit-click_42';
        $result = $this->reader->read($this->request([], ['_rdt_cid' => $raw]));

        self::assertSame('t2_reddit-click_42', $result['rdt_cid'] ?? null);
        self::assertStringNotContainsString('|', $result['rdt_cid']);
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $request = $this->request(['oppref' => '  url-oppref  '], ['_rdt_cid' => 'v2|1791400000000| rdt-id ']);

        self::assertSame(['rdt_cid' => 'rdt-id', 'oppref' => 'url-oppref'], $this->reader->read($request));
    }

    public function testAllClickIdsAreReadTogether(): void
    {
        $cookies = [];
        $expected = [];
        foreach (self::clickIdProvider() as [$param, $cookieName, , $clickId]) {
            $cookies[$cookieName] = $this->cookie(self::NOW_MS - self::DAY_MS, $clickId);
            $expected[$param] = $clickId;
        }

        self::assertSame($expected, $this->reader->read($this->request([], $cookies)));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unwrapProvider(): iterable
    {
        yield 'web SDK cookie format' => ['v2|1791400000000|abc_123', 'abc_123'];
        yield 'expired cookie format is still unwrapped' => ['v2|1000000000000|abc_123', 'abc_123'];
        yield 'bare click id unchanged' => ['abc_123', 'abc_123'];
        yield 'malformed versioned value unchanged' => ['v2|x|abc', 'v2|x|abc'];
    }

    #[DataProvider('unwrapProvider')]
    public function testUnwrap(string $value, string $expected): void
    {
        self::assertSame($expected, PersistedClickIdReader::unwrap($value));
    }

    public function testDefaultClockIsTheSystemClock(): void
    {
        $reader = new PersistedClickIdReader();
        $fresh = 'v2|' . (int) floor(microtime(true) * 1000) . '|fresh-click';

        self::assertSame(['gclid' => 'fresh-click'], $reader->read($this->request([], ['_gclid' => $fresh])));
    }

    private function cookie(int $firstSeenMs, string $clickId): string
    {
        return 'v2|' . $firstSeenMs . '|' . $clickId;
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $cookies
     */
    private function request(array $query, array $cookies): Request
    {
        return new Request($query, [], [], $cookies);
    }
}
