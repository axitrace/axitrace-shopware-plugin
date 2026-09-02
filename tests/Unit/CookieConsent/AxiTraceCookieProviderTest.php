<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\CookieConsent;

use AxitraceShopware6\CookieConsent\AxiTraceCookieProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

/*
 * The plugin deliberately requires only shopware/core (storefront is always
 * present in a Shopware installation, but not in the dev vendor). The stub
 * below mirrors the real interface's single method so this guard test can
 * run in the plain unit suite.
 */
if (!\interface_exists(CookieProviderInterface::class)) {
    eval('namespace Shopware\Storefront\Framework\Cookie; interface CookieProviderInterface { public function getCookieGroups(): array; }');
}

/**
 * Guards the cookie-group contract of the consent feature:
 *
 * - the master entry (axitrace-enabled, value '1') is what makes Shopware's
 *   own consent manager able to actually GRANT AxiTrace out of the box —
 *   without it the group is disclosure-only and the native banner cannot
 *   unblock the tracking;
 * - the three vt_* disclosure entries must survive any future edit, because
 *   Shopware removes exactly the declared cookies when the shopper declines.
 */
final class AxiTraceCookieProviderTest extends TestCase
{
    private CookieProviderInterface&MockObject $inner;
    private AxiTraceCookieProvider $provider;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(CookieProviderInterface::class);
        $this->provider = new AxiTraceCookieProvider($this->inner);
    }

    public function testGroupContainsMasterConsentEntryWithValueOne(): void
    {
        $this->inner->method('getCookieGroups')->willReturn([]);

        $group = $this->provider->getCookieGroups()[0];
        $entries = $group['entries'];

        $master = null;

        foreach ($entries as $entry) {
            if (($entry['cookie'] ?? null) === AxiTraceCookieProvider::DEFAULT_CONSENT_COOKIE) {
                $master = $entry;

                break;
            }
        }

        self::assertNotNull($master, 'Master consent entry is missing from the AxiTrace group');
        self::assertSame('1', $master['value']);
        self::assertSame('365', $master['expiration']);
    }

    public function testMasterEntryIsFirstInTheGroup(): void
    {
        $this->inner->method('getCookieGroups')->willReturn([]);

        $entries = $this->provider->getCookieGroups()[0]['entries'];

        self::assertSame(
            AxiTraceCookieProvider::DEFAULT_CONSENT_COOKIE,
            $entries[0]['cookie'],
            'The master consent entry must render as the group’s leading control',
        );
    }

    public function testVtDisclosureEntriesSurviveUnchanged(): void
    {
        $this->inner->method('getCookieGroups')->willReturn([]);

        $entries = $this->provider->getCookieGroups()[0]['entries'];
        $byCookie = [];

        foreach ($entries as $entry) {
            $byCookie[$entry['cookie']] = $entry;
        }

        foreach (['vt_vid' => '730', 'vt_sid' => '0', 'vt_uid' => '0'] as $cookie => $expiration) {
            self::assertArrayHasKey($cookie, $byCookie, "Disclosure entry $cookie was dropped");
            self::assertSame('', $byCookie[$cookie]['value'], "$cookie must stay disclosure-only (value '')");
            self::assertSame($expiration, $byCookie[$cookie]['expiration']);
        }
    }

    public function testInnerGroupsArePassedThrough(): void
    {
        $innerGroup = ['snippet_name' => 'Required', 'entries' => []];

        $this->inner->method('getCookieGroups')->willReturn([$innerGroup]);

        $groups = $this->provider->getCookieGroups();

        self::assertCount(2, $groups);
        self::assertSame($innerGroup, $groups[0]);
    }
}
