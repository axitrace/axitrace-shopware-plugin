# Changelog

All notable changes to the AxiTrace Shopware 6 plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] - 2026-10-02

### Added
- **Optional AxiTrace secret key** setting (per Sales Channel, password field). It is needed only for profit tracking and is used server-to-server only, never in the storefront. Requests made with it carry `Authorization: Basic base64(<secret key>:)`. Find it in AxiTrace under Settings, in the Container Information card, as Secret Key. Leave it empty and the plugin sends no Authorization header, no product costs and no refunds; the only difference from 0.4.2 is that purchases now also carry `externalId` on every order line and `taxesIncluded` on the order (see below).
- **Cost of goods on purchases** (secret key only): each order line carries `unitCost`, the net purchase price from the product's purchase prices in the order currency. A variant without its own purchase price uses its parent's. A gross-only purchase price is converted to net with the line's tax rate when the price is linked. A line gets no cost when the purchase price is missing, zero, unlinked gross-only, or kept in another currency than the order; AxiTrace then applies the workspace's default margin.
- **Product reference and tax status on purchases**: every order line carries `externalId` (`shopware:<product id>`), and the order carries `taxesIncluded` from its tax status (gross, net or tax-free). The existing `tax`, `shipping`, `revenue` and `value` keys and the *Conversion value* setting are unchanged.
- **Refunds and cancellations** (secret key only) are reported to AxiTrace and reduce profit and POAS; ROAS and the purchase already sent to the ad platforms stay unchanged. Triggers: a payment transaction entering `refunded` or `refunded_partially`, a paid order entering `cancelled`, and a paid payment cancelled directly. Amounts come from the refunds a payment integration recorded in Shopware (with their lines), else the whole transaction amount for a full refund; a cancellation sends what has not already been refunded. A partial refund with no recorded amount is logged as a warning and not sent. Every refund has a deterministic id, so a repeated transition or a retry is counted once.

- **A rejected secret key never costs a purchase.** When AxiTrace answers 401 to a purchase sent with the key (a wrong key, or the key of another workspace), the rejection is logged critical (the key itself is never logged) and the purchase is sent once more without the Authorization header and without `unitCost`. A refund whose key is rejected is not sent and is not queued for a retry, since refunds are only sent with a valid key.

### Fixed
- Refunds and cancellations now carry the identifier AxiTrace stores the purchase under: the order number, or the order id when the order has no number. Purchase and refund read it from one shared helper (`OrderReference`). Before this fix refunds carried the order id while purchases are stored under the order number, so no refund matched its purchase.

### Changed
- Undeliverable refunds join undeliverable purchases in the retry queue. The queue stores which endpoint and Sales Channel a request belongs to and reads the secret key again when it retries, so the key itself is never stored there. A queued purchase retried after the key was removed is sent without its cost fields.

## [0.4.2] - 2026-09-29

### Fixed
- Customer enrichment now follows the effective consent policy supplied by SDK 0.21.2. With workspace consent checks and plugin gating off, no consent cookie is required. Explicit plugin gates still require consent.

## [0.4.1] - 2026-09-28

### Fixed
- Explicitly load the billing country state for paid-order events, so an available canton/province is included in server-side matching data.

## [0.4.0] - 2026-09-28

### Added

- Optional Pinterest-only catalog identifiers based on the actual Shopware variant product number, with a lowercase mode for normalized feeds and a deterministic UUID fallback when a product number is unavailable. Other destinations keep their existing identifiers.
- A private, no-store storefront-context endpoint that returns the current cart and current-sales-channel product metadata after successful cart changes. Customer match fields are included only when both an explicit consent header and the configured consent cookie prove a grant; no customer data is placed in shared HTML.
- Product brand, category and variant metadata where Shopware has loaded it, plus priced GA4-shaped cart items and total item quantity.
- Query-free checkout source URLs on server-side purchase events.

### Changed

- Product context now lives in the global metadata template so theme overrides of the configurator block cannot remove it.
- Storefront context failures are logged with class and message and displayed to the merchant instead of being silently swallowed.
- Purchase remains a server-only event emitted on the paid transition; this release does not add a browser Purchase event.

### Fixed

- Read product and checkout pages through Shopware's supported render-event parameters, restoring context that was previously lost after an unavailable method call.
- Explicitly load product categories for the initial product-page PageVisit and prefer translated brand/category names with a raw-name fallback.
- Escape inline JSON against script-closing product text, covered by an actual Twig rendering regression test.
- Remove a redundant inherited creation-time property declaration that caused a PHP fatal error on Shopware 6.6. The plugin suite passes against both 6.6.10.27 and 6.7.13.1.

## [0.3.0] - 2026-09-16

### Changed

- **A missing consent cookie is no longer recorded as a refusal.** Until now every order whose AxiTrace consent cookie was absent was stamped `denied` on the order (`axitrace_consent`) and reached AxiTrace as an explicit "the shopper said no" - including on stores that have no cookie banner at all, and for visitors who simply had not answered one yet. With the workspace-level **Cookie consent** policy switched on in the AxiTrace admin panel, such a purchase is stripped of its ad identifiers and never forwarded, so an invented refusal silently costs conversions. From this release a refusal needs proof: `denied` is stamped only when Shopware's own `cookie-preference` cookie shows the visitor saved a choice in the cookie configuration and the AxiTrace consent cookie is absent. A present consent cookie is still `granted`. With neither, the order carries no consent key at all and the workspace policy decides what happens to it.
- **Mode "Load immediately" hands the browser SDK no consent gate.** The SDK is now initialised with the public key, API URL and debug flag only - exactly what the loader passed before 0.2.0 - instead of an explicit `requireConsent: false`. The workspace **Cookie consent** policy is what governs tracking in this mode, on its own. The gating modes are unchanged: they still set `requireConsent` and hand the SDK the configured cookie, which can only tighten the workspace policy, never loosen it.

### Added

- **Consent withdrawal reaches the SDK.** In a gating mode, a shopper who reopens the cookie dialog and takes the consent away now triggers `Axitrace.revoke()`: the SDK stops sending and deletes the cookies and storage the grant allowed. The withdrawal is picked up from Shopware's `CookieConfiguration_Update` event when the consent cookie is gone, and the update listener is now registered whether or not the cookie was already present when the page loaded, so a shopper who accepted earlier is covered too.
- **`window.axitraceConsent.revoke()`** - the counterpart to `grant()`, for any CMP's "consent withdrawn" callback:

```js
window.axitraceConsent && window.axitraceConsent.revoke();
```

  A grant that follows a withdrawal works as expected: the SDK boots again with a fresh visitor identity. `window.axitraceConsent.isGranted()` reports `false` after a withdrawal.

## [0.2.0] - 2026-09-01

### Added

- **Consent gate for the browser SDK** (Extensions → AxiTrace Tracking → Configure → *Cookie consent*). New per-Sales-Channel *Cookie consent mode* setting with three modes: **Load immediately** (default - the behaviour of every previous release), **Wait for consent - browser tracking only**, and **Wait for consent - browser tracking and server-side order events**. In the gated modes the SDK is not loaded at all until the shopper's consent signal arrives - no cookie, no localStorage entry, no network request.
- Three interchangeable grant signals, all always active while gating is on: Shopware's own cookie consent manager (accepting the AxiTrace group now actually boots the SDK without a reload), any CMP's consent cookie via the new *Consent cookie name* setting (Acris, Usercentrics, Cookiebot, CCM19, …), and the new `window.axitraceConsent.grant()` JavaScript API - a one-line "on accept" callback for any consent tool. `window.axitraceConsent.isGranted()` reports the state.
- **Server-side purchase gating** as an explicit merchant choice (mode *…and server-side order events*): the shopper's consent decision is recorded on the order at placement (`axitrace_consent` custom field, written in every mode) and honoured at the `paid` transition. Fail-closed: orders created without a storefront session (admin orders, imports, API orders) carry no consent record and are not forwarded; every skip is logged at `warning` with order number and mode, without PII, and is never retried by the scheduled task.
- **Consent state reported to AxiTrace.** In a gating mode the browser SDK is initialised with `requireConsent` and receives the grant this plugin resolved, so every browser event carries `meta.consent`; the server-side purchase carries the decision recorded on the order as `data.consent`. This is what lets the workspace-level *Cookie consent* policy in the AxiTrace admin panel (Workspace → Domains) apply to Shopware stores.
- Shopware's AxiTrace cookie group gains an `axitrace-enabled` master entry (value `1`, 365-day expiry) so accepting the group in Shopware's native consent manager grants tracking out of the box.
- The consent decision for the browser SDK is made in the visitor's own browser, never rendered into the page. Shopware's HTTP cache keys pages on the URI and the `sw-cache-hash` / currency cookies only, so a server-rendered decision would be cached and served to the next visitor - a page cached for a shopper who accepted would have loaded the SDK for one who declined. The plugin renders only the policy (mode + cookie name), which is identical for every visitor and therefore safe to cache.

### Fixed

- **Documentation that described a consent gate the plugin did not have.** The README's *Cookie consent* section, the docs page (cookie-consent section, troubleshooting, GDPR FAQ) and the landing-page FAQ all claimed the browser SDK waited for consent - it did not. All surfaces now describe the real behaviour and the new *Cookie consent mode* setting. The docs also incorrectly named `CookieCollectEvent` as the registration mechanism (the plugin decorates `CookieProviderInterface`), and promised the Shopware 6.8 `CookieGroupCollectEvent` migration "in plugin v0.2.0" - corrected to the decoration pattern and "a future release" respectively.
- Removed the dead `consentRequired` flag from the storefront config block (no code ever read it).

## [0.1.9] - 2026-08-19

### Fixed

- **Critical: buyer-context capture at order placement never fired.** The subscriber was registered for `Shopware\Core\Checkout\Cart\Order\CheckoutOrderPlacedEvent` - a class that does not exist; Shopware dispatches `Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent`. Because `::class` on an unknown class resolves silently, the subscription registered but never matched, so the buyer's IP address, User-Agent, Meta cookies (`_fbp`/`_fbc`), Google Analytics cookies, TikTok/Reddit identifiers and AxiTrace visitor/session IDs were **never** written to the order - on every plugin version since 0.1.4. Purchases reached the ad platforms without these match keys unless AxiTrace could recover them server-side from the visitor profile. Fixed the event class; verified end-to-end on a live Shopware 6.6.10: all custom fields are now captured at order placement and present on the purchase payload.
- Regression guard: a unit test now pins the subscribed event to the exact canonical class name.

## [0.1.8] - 2026-08-18

### Added

- **Conversion value setting** (Extensions → AxiTrace Tracking → Configure → *Conversion value*). Choose which order amount is reported as the purchase value to every connected platform: order total incl. VAT and shipping (default, unchanged behaviour), incl. VAT excl. shipping, excl. VAT incl. shipping, or product revenue only (excl. VAT and shipping). Per sales channel, applies to new orders only.
- Purchase events now always carry the order's gross **VAT** (`tax`) and **shipping** amounts, so GA4 receives the `tax`/`shipping` purchase parameters and merchants can reconcile any value basis downstream.

### Fixed

- Unit test stubs updated for Shopware 6.6 entity signatures (no behaviour change).

## [0.1.7] - 2026-08-18

### Added

- Purchase events now carry the buyer's **first name, last name and state/province** from the billing address. Facebook CAPI and TikTok Events API hash and match on these, and until now they were never sent - measured across live orders, first name and state were absent from 100% of purchases.
- The **TikTok browser ID** (`_ttp`) and **Reddit browser and click IDs** (`_rdt_uuid`, `_rdt_cid`) are captured at order placement and forwarded, so TikTok Events API and Reddit Conversions API receive an identifier of their own instead of matching on e-mail alone.
- The AxiTrace **visitor and session IDs** (`vt_vid`, `vt_sid`) are captured and sent, becoming the `external_id` every destination matches on. This stitches the server-side purchase to the buyer's browsing profile; previously purchases carried no external ID at all.

### Fixed

- State is sent as the bare subdivision code. Shopware stores the fully qualified ISO 3166-2 form (`DE-BW`), which Meta's normalizer would have hashed as `debw` and never matched.
- The plugin version reported to AxiTrace had drifted from the one declared in `composer.json`.

### Notes

- **Update strongly recommended for stores still on 0.1.4 or older.** Buyer IP and User-Agent capture landed in 0.1.5; older stores send the shop server's identity instead, which AxiTrace correctly refuses to forward as the buyer's - leaving those purchases with no IP or User-Agent match key at all.

## [0.1.6] - 2026-08-13

### Added

- Full checkout-funnel tracking. The confirm page now emits **InitiateCheckout** and **AddPaymentInfo** to your connected ad platforms (Facebook CAPI, TikTok, etc.), in addition to the existing ViewContent, AddToCart and Purchase events - so the whole funnel is covered server-side by AxiTrace instead of relying on a browser pixel.
- These mid-funnel events now carry the real cart **value and currency**, read server-side from Shopware's confirm-page cart (`CheckoutConfirmPage`) and injected as an `axitrace-checkout-context` block. This lets Facebook/TikTok optimize on checkout value and improves event match quality. The injection is wrapped defensively and never interrupts the checkout render.

### Notes

- AddPaymentInfo fires for the payment method pre-selected on the confirm page and again if the customer switches method (deduplicated per page view). Detection uses Shopware's stable `paymentMethodId` field name, so it works across themes.

## [0.1.5] - 2026-08-12

### Added

- Purchase events now include the human-readable order number (`data.orderNumber`). Downstream it becomes the GA4 `transaction_id` and the AxiTrace `order_id`, enabling purchase deduplication and reconciliation against the shop admin.
- Purchase events now carry the buyer's real IP address and User-Agent, captured at order placement by `OrderPlacedSubscriber` (the "paid" transition runs server-side, where only the shop server's IP would be visible). Improves Facebook CAPI Event Match Quality.
- Purchase events now carry the buyer's Google Analytics cookies (`_ga` client id and `_ga_<container>` session) when present, so server-side GA4 purchases stitch to the buyer's on-site session instead of appearing as unattributed new users.
- Purchase events now carry the merchant's own Meta browser pixel cookies (`_fbp`/`_fbc`) when present. Captured by `OrderPlacedSubscriber` on `CheckoutOrderPlacedEvent` (the only point in the purchase flow that runs inside the customer's own checkout request - the "paid" state transition that triggers the actual purchase event send, handled by `OrderPaidSubscriber`, runs asynchronously for many payment methods with no request/cookie access), persisted to the order's `customFields`, and read back by `OrderEventNormalizer`. Improves Facebook CAPI browser/server event matching; no behavior change when cookies are absent.

## [0.1.4] - 2026-07-13

### Fixed

- **Critical:** client-side event tracking (PageView/ViewContent/AddToCart/InitiateCheckout via the AxiTrace JavaScript SDK) never fired. The storefront layout template injected the SDK script and an `#axitrace-config` data block, but nothing ever called `window.Axitrace.init()` - the deferred script loaded and sat inert. Added a bounded-poll bootstrap script (mirrors the AxiTrace Magento plugin's pixel bootstrap) to `meta.html.twig` that reads `#axitrace-config`, waits for `window.Axitrace.init` to become available, and initializes the SDK exactly once (guarded against double-execution).

## [0.1.3] - 2026-05-25

### Fixed

- **Critical:** `OrderPaidSubscriber::onOrderPaid` was type-hinted with `StateMachineStateChangeEvent` but Shopware dispatches the concrete subclass `OrderStateMachineStateChangeEvent` for `state_enter.order_transaction.state.paid`. PHP raised a TypeError BEFORE the try/catch could fire, causing the admin's "mark transaction paid" API call to return HTTP 500 (state-machine transition succeeded but plugin failed). Subscriber now type-hints `OrderStateMachineStateChangeEvent` and reads `getOrderId()` + `getOrder()` directly - eliminating the now-unnecessary two-step transaction→order lookup. The `order_transaction.repository` constructor argument is removed (services.xml updated accordingly).
- Verified end-to-end against dockware 6.6.10.5 + production ingestion-api: storefront order → admin "mark paid" → ingestion-api receives `transaction.charge` event with HTTP 202.

## [0.1.2] - 2026-05-25

### Fixed

- Drop `symfony/uid` requirement entirely - Shopware's Plugin Requirements Validator rejects installs that require packages not present in Shopware's `composer.lock`. `symfony/uid` is not shipped with Shopware. `UuidV5Generator` now uses self-contained raw SHA-1 (RFC 4122 §4.3) - same byte-identical algorithm as the AxiTrace Magento plugin, with cross-language parity tests against the Go counterpart.
- Drop `symfony/http-client` requirement - `HttpClientInterface` is already autoloadable via Shopware's transitive dependencies; declaring it as a direct require failed the same validator.

## [0.1.1] - 2026-05-25

### Changed

- Widen Symfony constraint from `^7.1` to `^6.4 || ^7.0` (superseded by v0.1.2 - both requirements removed entirely).

## [0.1.0] - 2026-05-25

### Added

- Initial release of the AxiTrace Shopware 6 plugin.
- Server-side `purchase` event forwarding via `OrderStateMachineStateChangeEvent` (triggers on transition to `paid`).
- Configuration system-config key `AxitraceShopware6.config.publicKey` for workspace public key.
- Failed-event retry table `axitrace_failed_event_log` with automatic cleanup on plugin uninstall (when user data removal is requested).
- Support for Facebook CAPI, TikTok Events API, Google Ads offline conversions, and GA4 - relayed through the AxiTrace ingestion endpoint.
- Cookie consent bridge: event forwarding honours Shopify/CookieBot consent signals via the AxiTrace JS SDK cookie (`_axi_consent`).

[Unreleased]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/axitrace/axitrace-shopware-plugin/releases/tag/v0.1.0
