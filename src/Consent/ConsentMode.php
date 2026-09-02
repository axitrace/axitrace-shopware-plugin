<?php

declare(strict_types=1);

namespace AxitraceShopware6\Consent;

/**
 * Whether and how the plugin holds tracking back until the shopper consents.
 *
 * `Off` (the default) is the behaviour of every release before 0.2.0: the
 * browser SDK loads immediately and every server-side order event is sent.
 *
 * This enum is also the reason a plugin update can never silently start
 * gating: Shopware writes config.xml `defaultValue`s on *install* only, so
 * on every existing install `SystemConfigService::get()` returns `null` for
 * `consentMode` until the merchant saves the configuration form. `null`
 * therefore means "existing install that has never seen this setting" and
 * `Off` is the only safe answer for it.
 *
 * Backed values are the plugin's system-config option values (config.xml).
 */
enum ConsentMode: string
{
    /** Load immediately, send everything — the historical behaviour. */
    case Off = 'off';

    /** Hold the browser SDK until a consent signal; server-side order events are always sent. */
    case Browser = 'browser';

    /** Hold the browser SDK and forward server-side order events only when the shopper consented. */
    case All = 'all';

    /**
     * Lenient parser for the raw system-config value: null (an existing
     * install that has never saved the config form), empty or unknown values
     * fall back to Off instead of throwing, because a mistyped setting must
     * never gate tracking by accident.
     */
    public static function fromConfigValue(mixed $raw): self
    {
        if (!is_string($raw)) {
            return self::Off;
        }

        return self::tryFrom(strtolower(trim($raw))) ?? self::Off;
    }

    /** True when the browser SDK must wait for a consent signal before it may load. */
    public function requiresBrowserConsent(): bool
    {
        return $this !== self::Off;
    }

    /** True when server-side order events must also wait for the shopper's consent. */
    public function requiresServerConsent(): bool
    {
        return $this === self::All;
    }
}
