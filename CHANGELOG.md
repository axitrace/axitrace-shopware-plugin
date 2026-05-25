# Changelog

All notable changes to the AxiTrace Shopware 6 plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-05-25

### Fixed

- Widen Symfony constraint from `^7.1` to `^6.4 || ^7.0` for `symfony/uid` and `symfony/http-client` so the plugin installs cleanly on Shopware 6.6.x (ships Symfony 6.4) in addition to Shopware 6.7+ (ships Symfony 7).

## [0.1.0] - 2026-05-25

### Added

- Initial release of the AxiTrace Shopware 6 plugin.
- Server-side `purchase` event forwarding via `OrderStateMachineStateChangeEvent` (triggers on transition to `paid`).
- Configuration system-config key `AxitraceShopware6.config.publicKey` for workspace public key.
- Failed-event retry table `axitrace_failed_event_log` with automatic cleanup on plugin uninstall (when user data removal is requested).
- Support for Facebook CAPI, TikTok Events API, Google Ads offline conversions, and GA4 — relayed through the AxiTrace ingestion endpoint.
- Cookie consent bridge: event forwarding honours Shopify/CookieBot consent signals via the AxiTrace JS SDK cookie (`_axi_consent`).

[Unreleased]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/axitrace/axitrace-shopware-plugin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/axitrace/axitrace-shopware-plugin/releases/tag/v0.1.0
