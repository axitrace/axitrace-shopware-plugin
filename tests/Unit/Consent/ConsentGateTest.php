<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Consent;

use AxitraceShopware6\Consent\ConsentGate;
use AxitraceShopware6\Consent\ConsentMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The whole consent decision, as pure functions — runs without Shopware.
 *
 * Every branch of every method is covered here; the storefront and order
 * subscribers are only adapters around this class.
 */
final class ConsentGateTest extends TestCase
{
    private ConsentGate $gate;

    protected function setUp(): void
    {
        $this->gate = new ConsentGate();
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function grantSignalProvider(): iterable
    {
        yield 'null cookie → deny'                          => [null, false];
        yield "'' → deny"                                   => ['', false];
        yield "' ' whitespace-only → deny"                  => [' ', false];
        yield "'0' → deny"                                  => ['0', false];
        yield "'1' → grant"                                 => ['1', true];
        yield "'false' → deny"                              => ['false', false];
        yield "'FALSE' case-insensitive → deny"             => ['FALSE', false];
        yield "'  Denied ' padded + case → deny"            => ['  Denied ', false];
        yield "'deny' → deny"                               => ['deny', false];
        yield "'yes' → grant"                               => ['yes', true];
        yield "'{\"marketing\":true}' JSON blob → grant by presence" => ['{"marketing":true}', true];
        yield "'opt-out' → deny"                            => ['opt-out', false];
        yield "'DENIED' uppercase → deny"                   => ['DENIED', false];
    }

    #[DataProvider('grantSignalProvider')]
    public function testIsGrantSignal(?string $raw, bool $expected): void
    {
        self::assertSame($expected, $this->gate->isGrantSignal($raw));
    }

    /**
     * Full 3-mode × decision matrix for the server gate.
     *
     * @return iterable<string, array{ConsentMode, ?string, bool}>
     */
    public static function serverMatrixProvider(): iterable
    {
        yield 'Off   + null decision → sent'           => [ConsentMode::Off, null, true];
        yield 'Off   + denied → sent'                  => [ConsentMode::Off, ConsentGate::DECISION_DENIED, true];
        yield 'Off   + granted → sent'                 => [ConsentMode::Off, ConsentGate::DECISION_GRANTED, true];
        yield 'Browser + null decision → sent'         => [ConsentMode::Browser, null, true];
        yield 'Browser + denied → sent'                => [ConsentMode::Browser, ConsentGate::DECISION_DENIED, true];
        yield 'Browser + granted → sent'               => [ConsentMode::Browser, ConsentGate::DECISION_GRANTED, true];
        yield 'All   + granted → sent'                 => [ConsentMode::All, ConsentGate::DECISION_GRANTED, true];
        yield 'All   + denied → NOT sent'              => [ConsentMode::All, ConsentGate::DECISION_DENIED, false];
        yield 'All   + null decision → NOT sent (fail-closed)' => [ConsentMode::All, null, false];
        yield 'All   + garbage decision → NOT sent'    => [ConsentMode::All, 'nonsense', false];
    }

    #[DataProvider('serverMatrixProvider')]
    public function testAllowsServerTracking(ConsentMode $mode, ?string $storedDecision, bool $expected): void
    {
        self::assertSame($expected, $this->gate->allowsServerTracking($mode, $storedDecision));
    }
}
