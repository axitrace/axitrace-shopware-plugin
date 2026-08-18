<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

/**
 * Which order amount is reported as the conversion value of a purchase.
 *
 * Merchants bidding on margin-relevant revenue (Google Ads tROAS, Meta value
 * optimisation) often want VAT and shipping excluded, because those are
 * pass-through amounts and not revenue the ad spend actually generated.
 * The default keeps the historical behaviour (order total incl. VAT and
 * shipping) so upgrading stores see no change until they opt in.
 *
 * Backed values are the plugin's system-config option values (config.xml).
 */
enum ConversionValueBasis: string
{
    /** Order total as paid by the buyer — including VAT and shipping. */
    case GrossTotal = 'gross_total';

    /** Order total including VAT, without shipping costs. */
    case GrossExclShipping = 'gross_excl_shipping';

    /** Order total excluding VAT, including net shipping costs. */
    case NetTotal = 'net_total';

    /** Product revenue only — excluding VAT and shipping. */
    case NetExclShipping = 'net_excl_shipping';

    /**
     * Lenient parser for the raw system-config value: unknown, empty or
     * legacy values fall back to the historical default instead of throwing,
     * because a mistyped setting must never stop purchase tracking.
     */
    public static function fromConfigValue(mixed $raw): self
    {
        if (!is_string($raw)) {
            return self::GrossTotal;
        }

        return self::tryFrom(strtolower(trim($raw))) ?? self::GrossTotal;
    }
}
