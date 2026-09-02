<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Consent;

use AxitraceShopware6\Consent\ConsentMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure enum behaviour — runs without a Shopware installation.
 *
 * The null → Off rule is the single most important correctness detail of the
 * consent feature: Shopware writes config.xml `defaultValue`s on install only,
 * so every existing install returns null for `consentMode` until the merchant
 * saves the form. A wrong default here would silently gate every existing
 * merchant on update.
 */
final class ConsentModeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ConsentMode}>
     */
    public static function configValueProvider(): iterable
    {
        // The update-path cases — an install that never saved the config form.
        yield 'null means never configured → Off' => [null, ConsentMode::Off];
        yield 'empty string → Off'                 => ['', ConsentMode::Off];
        yield 'integer zero → Off'                 => [0, ConsentMode::Off];
        yield 'false → Off'                        => [false, ConsentMode::Off];
        yield 'array → Off'                        => [[], ConsentMode::Off];

        // Unknown values must never throw and never gate.
        yield 'nonsense string → Off'              => ['nonsense', ConsentMode::Off];

        // Every valid option value, including case/whitespace tolerance.
        yield "'off' → Off"                        => ['off', ConsentMode::Off];
        yield "'browser' → Browser' "              => ['browser', ConsentMode::Browser];
        yield "'all' → All"                        => ['all', ConsentMode::All];
        yield "'ALL' uppercase → All' "            => ['ALL', ConsentMode::All];
        yield "' Browser ' padded → Browser' "     => [' Browser ', ConsentMode::Browser];
    }

    #[DataProvider('configValueProvider')]
    public function testFromConfigValue(mixed $raw, ConsentMode $expected): void
    {
        self::assertSame($expected, ConsentMode::fromConfigValue($raw));
    }

    /**
     * @return iterable<string, array{ConsentMode, bool, bool}>
     */
    public static function requirementProvider(): iterable
    {
        yield 'Off     → no browser, no server consent' => [ConsentMode::Off, false, false];
        yield 'Browser → browser consent, server ungated' => [ConsentMode::Browser, true, false];
        yield 'All     → browser + server consent'      => [ConsentMode::All, true, true];
    }

    #[DataProvider('requirementProvider')]
    public function testConsentRequirements(ConsentMode $mode, bool $browser, bool $server): void
    {
        self::assertSame($browser, $mode->requiresBrowserConsent());
        self::assertSame($server, $mode->requiresServerConsent());
    }
}
