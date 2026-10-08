<?php

declare(strict_types=1);

namespace AxitraceShopware6\ClickId;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the ad click ids of the current storefront request: from the URL when the
 * request carries one, otherwise from the first-party cookies the AxiTrace web SDK
 * persists them in, otherwise (Microsoft, X, Pinterest, LinkedIn) from the cookie the
 * platform's own tag writes.
 *
 * Why this exists: the web SDK reads a click id from the landing URL and keeps it in
 * a first-party cookie (`_gclid`, `_ttclid`, ...), because the purchase happens on a
 * later request whose URL no longer carries it. Up to 0.5.0 the plugin read none of
 * those cookies, so server-side purchases reached TikTok without their ttclid and
 * Google Ads without their gclid, and it forwarded `_rdt_cid` in its raw cookie
 * format, so Reddit received "v2|<firstSeenMs>|<clickId>" as the click id.
 *
 * Cookie format written by the web SDK (`setClickIdCookie` in web-sdk/velitrack-sdk.js):
 * "v2|<firstSeenMs>|<clickId>". The read mirrors the web SDK's own `getClickIdCookie`
 * and the PHP SDK 1.10.0 `parseClickIdCookie`: an unversioned (legacy) value, a missing
 * or non-numeric timestamp, an empty click id and a click older than the web SDK's
 * maximum age all yield nothing, so the server never replays a click the browser
 * itself would no longer send.
 *
 * A click id in the current URL wins over the cookie (a fresh ad click replaces the
 * stored one), except when it is the very click the cookie already holds and that
 * click is past its maximum age: the web SDK treats that as a bookmarked or shared
 * landing URL, not a new ad click, and drops it (`isKnownStaleCookie`).
 *
 * Web SDK 0.24.0 added msclkid, twclid, epik, li_fat_id and sccid (PERSISTED_CLICK_IDS
 * in velitrack-sdk.js). Their cookies carry an "_axi_" prefix because the plain names
 * belong to the platforms' tags. When neither the URL nor that cookie has the click,
 * the platform's own cookie (VENDOR_COOKIES) is read, never written: the SDK's
 * `readVendorClickId`. Snap's URL parameter is "ScCid" (case-sensitive); "sccid" is
 * accepted too, "ScCid" first.
 *
 * Pure apart from the injected clock: no I/O, no side effects.
 */
final class PersistedClickIdReader
{
    /**
     * Click id key => [cookie name, maximum age in days], exactly as the web SDK writes
     * them: 90 days for Google, TikTok, Microsoft and X, 60 for Pinterest, 30 for
     * LinkedIn, 28 for Reddit, OpenAI Ads and Snapchat. The keys are the flat keys
     * event-worker reads from `data` and, unless URL_PARAMS says otherwise, the URL
     * parameter the click arrives in.
     */
    public const CLICK_IDS = [
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
    ];

    /**
     * URL parameters, in priority order, of a click id whose parameter differs from its
     * key. Snap's own parameter is "ScCid" (query keys are case-sensitive).
     */
    public const URL_PARAMS = [
        'sccid' => ['ScCid', 'sccid'],
    ];

    /**
     * The cookie the platform's own tag keeps the click id in, read only as the last
     * fallback: UET, the X pixel, the Pinterest tag, the LinkedIn Insight Tag. Snap
     * documents no such cookie.
     */
    public const VENDOR_COOKIES = [
        'msclkid' => '_uetmsclkid',
        'twclid' => '_twclid',
        'epik' => '_epik',
        'li_fat_id' => 'li_fat_id',
    ];

    private const VERSION_PREFIX = 'v2|';

    private const MS_PER_DAY = 86_400_000;

    /**
     * A click id is an opaque token of printable ASCII without spaces. Anything longer
     * than the bound or carrying other bytes is rejected rather than truncated: a cut
     * click id matches no click and would only pollute the destination's match data.
     */
    private const CLICK_ID_PATTERN = '/^[\x21-\x7E]{1,500}$/';

    /** @var \Closure(): int */
    private readonly \Closure $nowMs;

    /**
     * @param (\Closure(): int)|null $nowMs Current time in Unix milliseconds; the system clock when null
     */
    public function __construct(?\Closure $nowMs = null)
    {
        $this->nowMs = $nowMs ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    /**
     * Every click id this request carries, keyed by its CLICK_IDS key.
     * A click id that is absent, malformed or expired is simply not in the result.
     *
     * @return array<string, string>
     */
    public function read(Request $request): array
    {
        $now = ($this->nowMs)();
        $clickIds = [];

        $query = $request->query->all();

        foreach (self::CLICK_IDS as $key => [$cookieName, $maxAgeDays]) {
            $cookie = $request->cookies->get($cookieName);
            $cookie = is_string($cookie) ? $cookie : null;

            $fromUrl = null;
            foreach (self::URL_PARAMS[$key] ?? [$key] as $param) {
                $fromUrl = $this->fromUrl($query[$param] ?? null);
                if ($fromUrl !== null) {
                    break;
                }
            }

            if ($fromUrl !== null) {
                if (!$this->isKnownStaleCookie($cookie, $fromUrl, $maxAgeDays, $now)) {
                    $clickIds[$key] = $fromUrl;
                }
                continue;
            }

            $parsed = $cookie !== null ? self::parse($cookie) : null;
            if ($parsed !== null && !self::isExpired($parsed['firstSeenMs'], $maxAgeDays, $now)) {
                $clickIds[$key] = $parsed['clickId'];
                continue;
            }

            $vendorCookie = self::VENDOR_COOKIES[$key] ?? null;
            if ($vendorCookie === null) {
                continue;
            }

            $vendorValue = $request->cookies->get($vendorCookie);
            $fromVendor = is_string($vendorValue) ? self::fromVendorCookie($vendorCookie, $vendorValue) : null;
            if ($fromVendor !== null) {
                $clickIds[$key] = $fromVendor;
            }
        }

        return $clickIds;
    }

    /**
     * The click id inside a value in the web SDK cookie format, or the value itself when
     * it is not in that format. No age check: for values captured at order placement,
     * where the age was already enforced, and stored by a plugin version (0.5.0 and
     * older) that persisted the raw `_rdt_cid` cookie.
     */
    public static function unwrap(string $value): string
    {
        $parsed = self::parse($value);

        return $parsed !== null ? $parsed['clickId'] : $value;
    }

    /**
     * @return array{firstSeenMs: int, clickId: string}|null
     */
    private static function parse(string $raw): ?array
    {
        if (!str_starts_with($raw, self::VERSION_PREFIX)) {
            return null;
        }

        $parts = explode('|', $raw, 3);
        if (count($parts) !== 3 || preg_match('/^\d{1,15}$/', $parts[1]) !== 1) {
            return null;
        }

        $firstSeenMs = (int) $parts[1];
        $clickId = trim($parts[2]);
        if ($firstSeenMs <= 0 || preg_match(self::CLICK_ID_PATTERN, $clickId) !== 1) {
            return null;
        }

        return ['firstSeenMs' => $firstSeenMs, 'clickId' => $clickId];
    }

    /**
     * The click id inside a platform-owned cookie, or null. Formats, as the web SDK's
     * readVendorClickId() reads them:
     *   _uetmsclkid      - UET writes "_uet" + msclkid; a bare msclkid is accepted too;
     *   _twclid          - the X pixel writes JSON {"twclid": "...", ...}; the X
     *                      server-side tag writes the bare twclid;
     *   _epik, li_fat_id - the bare click id.
     */
    private static function fromVendorCookie(string $cookieName, string $raw): ?string
    {
        $value = trim($raw);

        if ($cookieName === '_uetmsclkid' && str_starts_with($value, '_uet')) {
            $value = substr($value, 4);
        } elseif ($cookieName === '_twclid' && str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) && is_string($decoded['twclid'] ?? null) ? trim($decoded['twclid']) : '';
        }

        return preg_match(self::CLICK_ID_PATTERN, $value) === 1 ? $value : null;
    }

    private function fromUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::CLICK_ID_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * True when the cookie already holds this very click and that click is past its
     * maximum age, or holds it in the unversioned legacy format whose age is unknown.
     * Mirrors the web SDK's isKnownStaleCookie().
     */
    private function isKnownStaleCookie(?string $cookie, string $clickId, int $maxAgeDays, int $now): bool
    {
        if ($cookie === null || $cookie === '') {
            return false;
        }

        if (!str_starts_with($cookie, self::VERSION_PREFIX)) {
            return trim($cookie) === $clickId;
        }

        $parsed = self::parse($cookie);
        if ($parsed === null || $parsed['clickId'] !== $clickId) {
            return false;
        }

        return self::isExpired($parsed['firstSeenMs'], $maxAgeDays, $now);
    }

    private static function isExpired(int $firstSeenMs, int $maxAgeDays, int $now): bool
    {
        return $now - $firstSeenMs > $maxAgeDays * self::MS_PER_DAY;
    }
}
