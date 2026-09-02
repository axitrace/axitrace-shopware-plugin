<?php

declare(strict_types=1);

namespace AxitraceShopware6\Consent;

/**
 * Every consent rule of the plugin, as pure functions.
 *
 * The server-side gate (OrderPaidSubscriber) and the order-placement recorder
 * (OrderPlacedSubscriber) stay thin adapters around this class, so the whole
 * decision is exhaustively unit-testable without a Shopware installation.
 *
 * The BROWSER gate deliberately lives in JavaScript (meta.html.twig), not here:
 * Shopware's HTTP cache does not key pages on the consent cookie, so a
 * server-side browser decision would be cached and served to the next visitor.
 * `isGrantSignal()` is nevertheless the canonical definition of "this cookie
 * value means consent" — the bootstrap's readConsentCookie() mirrors it, and
 * the two must be changed together.
 *
 * Fail-closed by design: an order with no recorded consent decision is NOT
 * forwarded when the mode requires server consent — it is never treated as
 * granted. The same sentence appears in the config.xml help text and the
 * README.
 */
final class ConsentGate
{
    /** The only customFields value meaning "the shopper consented". */
    public const DECISION_GRANTED = 'granted';

    /** The only customFields value meaning "the shopper declined". */
    public const DECISION_DENIED = 'denied';

    /**
     * The default consent cookie name. Canonical home of the literal so that
     * consent logic stays free of Storefront dependencies: AxiTraceCookieProvider
     * (which needs the Storefront interface) re-exports it as
     * AxiTraceCookieProvider::DEFAULT_CONSENT_COOKIE.
     */
    public const DEFAULT_CONSENT_COOKIE = 'axitrace-enabled';

    /** Cookie values that are present but do NOT mean consent. */
    private const DENY_VALUES = ['', '0', 'false', 'no', 'deny', 'denied', 'opt-out'];

    /**
     * A consent cookie is a grant signal by its presence: Shopware sets the
     * declared value ('1') on accept and deletes the cookie on decline, so
     * absence is deny. The deny-list defends against CMPs that keep the
     * cookie and flip its value instead of deleting it. A CMP that stores a
     * JSON blob is correctly treated as a grant by presence.
     */
    public function isGrantSignal(?string $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        return !in_array(strtolower(trim($raw)), self::DENY_VALUES, true);
    }

    /**
     * May this purchase be forwarded to the ingestion API in this mode, given
     * the consent decision recorded at order placement? A missing decision
     * (order placed before the upgrade, or created in the admin / via API /
     * by an import) is not treated as granted — fail-closed.
     */
    public function allowsServerTracking(ConsentMode $mode, ?string $storedDecision): bool
    {
        if (!$mode->requiresServerConsent()) {
            return true;
        }

        return $storedDecision === self::DECISION_GRANTED;
    }
}
