<?php

declare(strict_types=1);

namespace AxitraceShopware6\CookieConsent;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

/**
 * Registers AxiTrace cookies in the Shopware cookie consent manager via the decorator pattern.
 *
 * @suppress PhanDeprecatedInterface
 */
final class AxiTraceCookieProvider implements CookieProviderInterface
{
    public function __construct(
        private readonly CookieProviderInterface $inner,
    ) {}

    public function getCookieGroups(): array
    {
        $cookies = $this->inner->getCookieGroups();

        $cookies[] = [
            'snippet_name'        => 'AxiTrace Tracking',
            'snippet_description' => 'Server-side conversion tracking for ad platforms (Facebook, TikTok, Google Ads). Reads vt_vid, vt_sid, vt_uid cookies set by the AxiTrace browser SDK.',
            'entries'             => [
                [
                    'snippet_name'        => 'vt_vid',
                    'snippet_description' => 'AxiTrace visitor identifier (2-year expiry).',
                    'cookie'              => 'vt_vid',
                    'value'               => '',
                    'expiration'          => '730',
                ],
                [
                    'snippet_name'        => 'vt_sid',
                    'snippet_description' => 'AxiTrace session identifier (30-minute expiry).',
                    'cookie'              => 'vt_sid',
                    'value'               => '',
                    'expiration'          => '0',
                ],
                [
                    'snippet_name'        => 'vt_uid',
                    'snippet_description' => 'AxiTrace authenticated user identifier (session cookie).',
                    'cookie'              => 'vt_uid',
                    'value'               => '',
                    'expiration'          => '0',
                ],
            ],
        ];

        return $cookies;
    }
}
