<?php

declare(strict_types=1);

namespace AxitraceShopware6\ClickId;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the ad click ids of the current storefront request: from the URL when the
 * request carries one, otherwise from the first-party cookies the AxiTrace web SDK
 * persists them in.
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
 * Pure apart from the injected clock: no I/O, no side effects.
 */
final class PersistedClickIdReader
{
    /**
     * Click id param name => [cookie name, maximum age in days], exactly as the web SDK
     * writes them: 90 days for Google and TikTok, 28 for Reddit and OpenAI Ads.
     * The param names are the flat keys event-worker reads from `data`.
     */
    public const CLICK_IDS = [
        'gclid' => ['_gclid', 90],
        'gbraid' => ['_gbraid', 90],
        'wbraid' => ['_wbraid', 90],
        'ttclid' => ['_ttclid', 90],
        'rdt_cid' => ['_rdt_cid', 28],
        'oppref' => ['_oppref', 28],
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
     * Every click id this request carries, keyed by its param name (see CLICK_IDS).
     * A click id that is absent, malformed or expired is simply not in the result.
     *
     * @return array<string, string>
     */
    public function read(Request $request): array
    {
        $now = ($this->nowMs)();
        $clickIds = [];

        foreach (self::CLICK_IDS as $param => [$cookieName, $maxAgeDays]) {
            $cookie = $request->cookies->get($cookieName);
            $cookie = is_string($cookie) ? $cookie : null;

            $fromUrl = $this->fromUrl($request->query->all()[$param] ?? null);
            if ($fromUrl !== null) {
                if (!$this->isKnownStaleCookie($cookie, $fromUrl, $maxAgeDays, $now)) {
                    $clickIds[$param] = $fromUrl;
                }
                continue;
            }

            if ($cookie === null) {
                continue;
            }

            $parsed = self::parse($cookie);
            if ($parsed !== null && !self::isExpired($parsed['firstSeenMs'], $maxAgeDays, $now)) {
                $clickIds[$param] = $parsed['clickId'];
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
