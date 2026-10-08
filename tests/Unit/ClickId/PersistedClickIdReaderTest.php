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
        // Web SDK 0.24.0, "_axi_" cookies.
        yield 'msclkid' => ['msclkid', '_axi_msclkid', 90, 'a1b2c3d4e5f60718293a4b5c6d7e8f90'];
        yield 'twclid' => ['twclid', '_axi_twclid', 90, '2-7abc1def2ghi3jkl4mno5pqr'];
        yield 'epik' => ['epik', '_axi_epik', 60, 'dj0yJnU9c2FtcGxlRXBpa1ZhbHVl'];
        yield 'li_fat_id' => ['li_fat_id', '_axi_li_fat_id', 30, 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'];
        yield 'sccid' => ['sccid', '_axi_sccid', 28, 'b2a1f3c4-5d6e-4f80-9a1b-2c3d4e5f6a7b'];
    }

    /**
     * Platform-owned cookie fallback: key, vendor cookie, raw value as the platform's
     * tag writes it, the bare click id.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function vendorCookieProvider(): iterable
    {
        yield 'msclkid with the UET "_uet" prefix' => ['msclkid', '_uetmsclkid', '_ueta1b2c3d4e5f60718293a4b5c6d7e8f90', 'a1b2c3d4e5f60718293a4b5c6d7e8f90'];
        yield 'msclkid bare' => ['msclkid', '_uetmsclkid', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'a1b2c3d4e5f60718293a4b5c6d7e8f90'];
        yield 'twclid as X pixel JSON' => ['twclid', '_twclid', '{"twclid":"2-7abc1def2ghi3jkl4mno5pqr","timestamp":1791460000000}', '2-7abc1def2ghi3jkl4mno5pqr'];
        yield 'twclid bare (X server-side tag)' => ['twclid', '_twclid', '2-7abc1def2ghi3jkl4mno5pqr', '2-7abc1def2ghi3jkl4mno5pqr'];
        yield 'epik bare' => ['epik', '_epik', 'dj0yJnU9c2FtcGxlRXBpa1ZhbHVl', 'dj0yJnU9c2FtcGxlRXBpa1ZhbHVl'];
        yield 'li_fat_id bare' => ['li_fat_id', 'li_fat_id', 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'];
    }

    public function testVendorCookiesAndUrlParamsMatchTheWebSdk(): void
    {
        self::assertSame(
            ['msclkid' => '_uetmsclkid', 'twclid' => '_twclid', 'epik' => '_epik', 'li_fat_id' => 'li_fat_id'],
            PersistedClickIdReader::VENDOR_COOKIES,
        );
        self::assertSame(['sccid' => ['ScCid', 'sccid']], PersistedClickIdReader::URL_PARAMS);
    }

    #[DataProvider('vendorCookieProvider')]
    public function testPlatformCookieIsTheLastFallback(string $key, string $vendorCookie, string $raw, string $clickId): void
    {
        self::assertSame([$key => $clickId], $this->reader->read($this->request([], [$vendorCookie => $raw])));
    }

    #[DataProvider('vendorCookieProvider')]
    public function testSdkCookieWinsOverThePlatformCookie(string $key, string $vendorCookie, string $raw, string $clickId): void
    {
        $request = $this->request([], [
            $vendorCookie => $raw,
            PersistedClickIdReader::CLICK_IDS[$key][0] => $this->cookie(self::NOW_MS - self::DAY_MS, 'sdk-cookie-click'),
        ]);

        self::assertSame([$key => 'sdk-cookie-click'], $this->reader->read($request));
    }

    #[DataProvider('vendorCookieProvider')]
    public function testUrlWinsOverThePlatformCookie(string $key, string $vendorCookie, string $raw, string $clickId): void
    {
        self::assertSame([$key => 'url-click'], $this->reader->read($this->request([$key => 'url-click'], [$vendorCookie => $raw])));
    }

    #[DataProvider('vendorCookieProvider')]
    public function testExpiredOrLegacySdkCookieFallsBackToThePlatformCookie(string $key, string $vendorCookie, string $raw, string $clickId): void
    {
        [$sdkCookie, $maxAgeDays] = PersistedClickIdReader::CLICK_IDS[$key];

        foreach ([$this->cookie(self::NOW_MS - ($maxAgeDays + 1) * self::DAY_MS, 'expired-click'), 'legacy-unversioned'] as $stored) {
            $request = $this->request([], [$vendorCookie => $raw, $sdkCookie => $stored]);

            self::assertSame([$key => $clickId], $this->reader->read($request), $stored);
        }
    }

    /**
     * A replayed bookmark is dropped outright, as in the web SDK: the URL branch never
     * consults the platform cookie.
     */
    #[DataProvider('vendorCookieProvider')]
    public function testBookmarkReplayIgnoresThePlatformCookieToo(string $key, string $vendorCookie, string $raw, string $clickId): void
    {
        [$sdkCookie, $maxAgeDays] = PersistedClickIdReader::CLICK_IDS[$key];
        $request = $this->request(
            [$key => 'replayed-click'],
            [$vendorCookie => $raw, $sdkCookie => $this->cookie(self::NOW_MS - ($maxAgeDays + 1) * self::DAY_MS, 'replayed-click')],
        );

        self::assertSame([], $this->reader->read($request));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedVendorCookieProvider(): iterable
    {
        yield 'UET prefix only' => ['_uetmsclkid', '_uet'];
        yield 'msclkid with a space' => ['_uetmsclkid', '_uetabc def'];
        yield 'msclkid over 500 characters' => ['_uetmsclkid', str_repeat('a', 501)];
        yield 'broken JSON' => ['_twclid', '{not json'];
        yield 'JSON without twclid' => ['_twclid', '{"other":"2-7abc"}'];
        yield 'JSON with a numeric twclid' => ['_twclid', '{"twclid":12345678}'];
        yield 'empty epik' => ['_epik', ''];
        yield 'li_fat_id with a space' => ['li_fat_id', 'has a space'];
    }

    #[DataProvider('malformedVendorCookieProvider')]
    public function testMalformedPlatformCookieIsIgnored(string $vendorCookie, string $raw): void
    {
        self::assertSame([], $this->reader->read($this->request([], [$vendorCookie => $raw])));
    }

    /** Snap documents no cookie holding the click id (_scid is the browser id). */
    public function testSnapHasNoPlatformCookieFallback(): void
    {
        $request = $this->request([], ['_scid' => 'b2a1f3c4-5d6e-4f80', 'sccid' => 'b2a1f3c4-5d6e-4f80', 'ScCid' => 'b2a1f3c4']);

        self::assertSame([], $this->reader->read($request));
    }

    public function testSnapClickIdIsReadFromItsCapitalisedUrlParameter(): void
    {
        self::assertSame(['sccid' => 'snap-click-1'], $this->reader->read($this->request(['ScCid' => 'snap-click-1'], [])));
    }

    public function testCapitalisedScCidWinsOverLowercaseSccid(): void
    {
        $request = $this->request(['ScCid' => 'snap-capital', 'sccid' => 'snap-lower'], []);

        self::assertSame(['sccid' => 'snap-capital'], $this->reader->read($request));
    }

    public function testInvalidScCidFallsBackToLowercaseSccid(): void
    {
        $request = $this->request(['ScCid' => 'has space', 'sccid' => 'snap-lower'], []);

        self::assertSame(['sccid' => 'snap-lower'], $this->reader->read($request));
    }

    public function testScCidReplayOfAnExpiredStoredSnapClickIsDropped(): void
    {
        $request = $this->request(
            ['ScCid' => 'snap-old'],
            ['_axi_sccid' => $this->cookie(self::NOW_MS - 29 * self::DAY_MS, 'snap-old')],
        );

        self::assertSame([], $this->reader->read($request));
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
                'msclkid' => ['_axi_msclkid', 90],
                'twclid' => ['_axi_twclid', 90],
                'epik' => ['_axi_epik', 60],
                'li_fat_id' => ['_axi_li_fat_id', 30],
                'sccid' => ['_axi_sccid', 28],
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
