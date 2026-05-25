# Changelog

All notable changes to the AxiTrace Shopware 6 plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/axitrace/axitrace-shopware-plugin/releases/tag/v0.1.0
