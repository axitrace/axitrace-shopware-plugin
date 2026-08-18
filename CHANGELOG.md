# Changelog

All notable changes to the AxiTrace Shopware 6 plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.8] - 2026-08-18

### Added

- **Conversion value setting** (Extensions → AxiTrace Tracking → Configure → *Conversion value*). Choose which order amount is reported as the purchase value to every connected platform: order total incl. VAT and shipping (default, unchanged behaviour), incl. VAT excl. shipping, excl. VAT incl. shipping, or product revenue only (excl. VAT and shipping). Per sales channel, applies to new orders only.
- Purchase events now always carry the order's gross **VAT** (`tax`) and **shipping** amounts, so GA4 receives the `tax`/`shipping` purchase parameters and merchants can reconcile any value basis downstream.

### Fixed

- Unit test stubs updated for Shopware 6.6 entity signatures (no behaviour change).

## [0.1.7] - 2026-08-18

### Added

- Purchase events now carry the buyer's **first name, last name and state/province** from the billing address. Facebook CAPI and TikTok Events API hash and match on these, and until now they were never sent — measured across live orders, first name and state were absent from 100% of purchases.
- The **TikTok browser ID** (`_ttp`) and **Reddit browser and click IDs** (`_rdt_uuid`, `_rdt_cid`) are captured at order placement and forwarded, so TikTok Events API and Reddit Conversions API receive an identifier of their own instead of matching on e-mail alone.
- The AxiTrace **visitor and session IDs** (`vt_vid`, `vt_sid`) are captured and sent, becoming the `external_id` every destination matches on. This stitches the server-side purchase to the buyer's browsing profile; previously purchases carried no external ID at all.

### Fixed

- State is sent as the bare subdivision code. Shopware stores the fully qualified ISO 3166-2 form (`DE-BW`), which Meta's normalizer would have hashed as `debw` and never matched.
- The plugin version reported to AxiTrace had drifted from the one declared in `composer.json`.

### Notes

- **Update strongly recommended for stores still on 0.1.4 or older.** Buyer IP and User-Agent capture landed in 0.1.5; older stores send the shop server's identity instead, which AxiTrace correctly refuses to forward as the buyer's — leaving those purchases with no IP or User-Agent match key at all.

## [0.1.6] - 2026-08-13

### Added

- Full checkout-funnel tracking. The confirm page now emits **InitiateCheckout** and **AddPaymentInfo** to your connected ad platforms (Facebook CAPI, TikTok, etc.), in addition to the existing ViewContent, AddToCart and Purchase events — so the whole funnel is covered server-side by AxiTrace instead of relying on a browser pixel.
- These mid-funnel events now carry the real cart **value and currency**, read server-side from Shopware's confirm-page cart (`CheckoutConfirmPage`) and injected as an `axitrace-checkout-context` block. This lets Facebook/TikTok optimize on checkout value and improves event match quality. The injection is wrapped defensively and never interrupts the checkout render.

### Notes

- AddPaymentInfo fires for the payment method pre-selected on the confirm page and again if the customer switches method (deduplicated per page view). Detection uses Shopware's stable `paymentMethodId` field name, so it works across themes.

## [0.1.5] - 2026-08-12

### Added

- Purchase events now include the human-readable order number (`data.orderNumber`). Downstream it becomes the GA4 `transaction_id` and the AxiTrace `order_id`, enabling purchase deduplication and reconciliation against the shop admin.
- Purchase events now carry the buyer's real IP address and User-Agent, captured at order placement by `OrderPlacedSubscriber` (the "paid" transition runs server-side, where only the shop server's IP would be visible). Improves Facebook CAPI Event Match Quality.
- Purchase events now carry the buyer's Google Analytics cookies (`_ga` client id and `_ga_<container>` session) when present, so server-side GA4 purchases stitch to the buyer's on-site session instead of appearing as unattributed new users.
- Purchase events now carry the merchant's own Meta browser pixel cookies (`_fbp`/`_fbc`) when present. Captured by `OrderPlacedSubscriber` on `CheckoutOrderPlacedEvent` (the only point in the purchase flow that runs inside the customer's own checkout request — the "paid" state transition that triggers the actual purchase event send, handled by `OrderPaidSubscriber`, runs asynchronously for many payment methods with no request/cookie access), persisted to the order's `customFields`, and read back by `OrderEventNormalizer`. Improves Facebook CAPI browser/server event matching; no behavior change when cookies are absent.

## [0.1.4] - 2026-07-13

### Fixed

- **Critical:** client-side event tracking (PageView/ViewContent/AddToCart/InitiateCheckout via the AxiTrace JavaScript SDK) never fired. The storefront layout template injected the SDK script and an `#axitrace-config` data block, but nothing ever called `window.Axitrace.init()` — the deferred script loaded and sat inert. Added a bounded-poll bootstrap script (mirrors the AxiTrace Magento plugin's pixel bootstrap) to `meta.html.twig` that reads `#axitrace-config`, waits for `window.Axitrace.init` to become available, and initializes the SDK exactly once (guarded against double-execution).

## [0.1.3] - 2026-05-25

### Fixed

- **Critical:** `OrderPaidSubscriber::onOrderPaid` was type-hinted with `StateMachineStateChangeEvent` but Shopware dispatches the concrete subclass `OrderStateMachineStateChangeEvent` for `state_enter.order_transaction.state.paid`. PHP raised a TypeError BEFORE the try/catch could fire, causing the admin's "mark transaction paid" API call to return HTTP 500 (state-machine transition succeeded but plugin failed). Subscriber now type-hints `OrderStateMachineStateChangeEvent` and reads `getOrderId()` + `getOrder()` directly — eliminating the now-unnecessary two-step transaction→order lookup. The `order_transaction.repository` constructor argument is removed (services.xml updated accordingly).
- Verified end-to-end against dockware 6.6.10.5 + production ingestion-api: storefront order → admin "mark paid" → ingestion-api receives `transaction.charge` event with HTTP 202.

## [0.1.2] - 2026-05-25

### Fixed

- Drop `symfony/uid` requirement entirely — Shopware's Plugin Requirements Validator rejects installs that require packages not present in Shopware's `composer.lock`. `symfony/uid` is not shipped with Shopware. `UuidV5Generator` now uses self-contained raw SHA-1 (RFC 4122 §4.3) — same byte-identical algorithm as the AxiTrace Magento plugin, with cross-language parity tests against the Go counterpart.
- Drop `symfony/http-client` requirement — `HttpClientInterface` is already autoloadable via Shopware's transitive dependencies; declaring it as a direct require failed the same validator.

## [0.1.1] - 2026-05-25

### Changed

- Widen Symfony constraint from `^7.1` to `^6.4 || ^7.0` (superseded by v0.1.2 — both requirements removed entirely).

## [0.1.0] - 2026-05-25

### Added

- Initial release of the AxiTrace Shopware 6 plugin.
- Server-side `purchase` event forwarding via `OrderStateMachineStateChangeEvent` (triggers on transition to `paid`).
- Configuration system-config key `AxitraceShopware6.config.publicKey` for workspace public key.
- Failed-event retry table `axitrace_failed_event_log` with automatic cleanup on plugin uninstall (when user data removal is requested).
- Support for Facebook CAPI, TikTok Events API, Google Ads offline conversions, and GA4 — relayed through the AxiTrace ingestion endpoint.
- Cookie consent bridge: event forwarding honours Shopify/CookieBot consent signals via the AxiTrace JS SDK cookie (`_axi_consent`).

[Unreleased]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/axitrace/axitrace-shopware-plugin/releases/tag/v0.1.0
