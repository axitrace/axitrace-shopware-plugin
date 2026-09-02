# AxiTrace for Shopware 6

Server-side tracking plugin for Shopware 6 stores. Forwards order and commerce
events to AxiTrace, which relays them to Facebook CAPI, TikTok Events API,
Google Ads offline conversions, and GA4 — server-side, with deterministic event
IDs that deduplicate against any client-side pixels you may also be running.

The plugin itself is **free** under the MIT License. AxiTrace bills the SaaS
that processes the forwarded events on
[axitrace.com](https://axitrace.com/pricing) (Stripe). There is no plugin-level
licence check or API call back to AxiTrace for billing purposes.

---

## What is AxiTrace?

AxiTrace is a server-side conversion tracking platform. When a customer
completes a purchase in your Shopware store, AxiTrace sends the event directly
from your server to advertising platforms (Facebook, TikTok, Google Ads, GA4)
— bypassing ad blockers and iOS 14+ restrictions that degrade client-side
pixels.

Key benefits:

- **Higher match rates** — server-to-server requests carry more signals than
  browser pixels blocked by extensions or Safari ITP.
- **Deduplication** — each event carries a stable UUID so the same conversion
  is never counted twice across server + client channels.
- **One dashboard** — all platforms in a single AxiTrace workspace; no need to
  log in to four separate ad accounts to verify tracking health.

---

## Requirements

| Component | Version |
|-----------|---------|
| Shopware | 6.6.8 or newer (< 7.0) |
| PHP | 8.2 / 8.3 / 8.4 |
| Composer | 2.x |

The plugin targets Shopware 6.6.x (Symfony 7 stack). Shopware 6.5 and below
are **not** supported.

---

## Installation

### Composer (recommended)

```bash
composer require axitrace/shopware6-tracking
bin/console plugin:install --activate AxitraceShopware6
bin/console cache:clear
```

### ZIP (for hosting without Composer access)

1. Download the latest ZIP from
   [axitrace.com/downloads/axitrace-shopware6-plugin-latest.zip](https://axitrace.com/downloads/axitrace-shopware6-plugin-latest.zip).
2. Extract the contents so that `AxitraceShopware6/` lives inside
   `custom/plugins/`.
3. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate AxitraceShopware6
   bin/console cache:clear
   ```

---

## Configuration

1. **Get your workspace public key**: sign in at
   [axitrace.com/dashboard](https://axitrace.com/dashboard). Each workspace
   has a `pk_live_...` / `pk_test_...` key. Copy it.
2. In the Shopware Administration go to **Extensions → My extensions →
   AxiTrace Tracking → Configure**.
3. **Enable AxiTrace**: set to **Yes**.
4. **Paste your workspace public key** into the *Public Key* field.
5. *(Optional)* Enter a custom **API base URL** if your AxiTrace workspace uses
   a custom ingestion domain. Leave blank to use the default
   (`api.axitrace.com`).
6. *(Optional)* Choose the **Conversion value** basis — which order amount is
   reported as the purchase value to Facebook, Google Ads, GA4, TikTok and
   Reddit. Default is the order total incl. VAT and shipping; you can exclude
   shipping and/or VAT (e.g. *Product revenue only — excl. VAT and shipping*
   for margin-based bidding). The setting applies to new orders only; the
   gross VAT and shipping amounts are always sent alongside for reference.
7. **Save** the configuration.
8. **Place a test order** in your storefront. Within 1–2 minutes the AxiTrace
   dashboard should show the order on the events feed.

---

## Events Captured

| Event | Trigger |
|-------|---------|
| `purchase` | Shopware `OrderStateMachineStateChangeEvent` fires when an order transitions to the `paid` state. Idempotent via the `axitrace_failed_event_log` unique constraint. |

Additional storefront events (ViewContent, AddToCart, InitiateCheckout) are
captured by the AxiTrace JavaScript SDK snippet, which you can add via a
Shopware Shopping Experience (CMS) block or through your theme's custom HTML.
See [axitrace.com/docs/integrations/shopware](https://axitrace.com/docs/integrations/shopware)
for the snippet.

PII (email, phone) is forwarded in **plain text** server-to-server; AxiTrace
hashes it internally per each platform's requirements before transmission.

---

## Cookie Consent

The plugin ships with a **Cookie consent mode** setting (per Sales Channel,
*Extensions → My extensions → AxiTrace Tracking → Configure → Cookie consent*).

### The three modes

| Mode | Browser SDK | Server-side order events |
|---|---|---|
| **Load immediately** *(default)* | loads at once | always sent |
| **Wait for consent — browser tracking only** | waits for consent | always sent |
| **Wait for consent — browser tracking and server-side order events** | waits for consent | sent only when the shopper consented |

The default keeps the behaviour of every release before 0.2.0: nothing is
gated until you opt in. Changes apply to **orders placed after saving**.

### How a consent grant is detected (all paths always active while gating is on)

1. **Shopware's own cookie consent manager** — the plugin registers an
   `axitrace-enabled` master entry in its AxiTrace cookie group; accepting the
   group sets `axitrace-enabled=1` and boots the SDK without a reload.
2. **Any consent cookie you configure** — enter the cookie name your CMP sets
   on accept (Acris, Usercentrics, Cookiebot, CCM19, …) under *Consent cookie
   name*. A bounded poll picks the cookie up within ~500 ms.
3. **The JavaScript API** — the universal escape hatch. One line from any CMP's
   "on accept" callback:

```js
window.axitraceConsent && window.axitraceConsent.grant();
```

Until a grant signal arrives, the SDK is not loaded at all — no cookie, no
localStorage entry, no network request. `window.axitraceConsent.isGranted()`
reports the current state.

Once granted, every browser event carries the consent state (`meta.consent`),
and the server-side purchase carries the decision recorded on the order
(`data.consent`), so the workspace-level **Cookie consent** policy in the
AxiTrace admin panel (Workspace → Domains) can decide whether an event is
forwarded to the ad platforms.

### Fail-closed for orders without a storefront session

In the strictest mode, orders created without a storefront session (admin
orders, imports, API orders) carry no consent record and are therefore **not
forwarded** — an order with no recorded consent decision is not treated as
granted. Every such skip is logged at `warning`. If you regularly create orders
outside the storefront, use *Wait for consent — browser tracking only* instead.

### Full guide

Third-party consent platforms (Cookiebot, Usercentrics, CCM19, …), the workspace-level
forwarding policy, and how to verify a gate actually works are covered in
[axitrace.com/docs/cookie-consent](https://axitrace.com/docs/cookie-consent).

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| No events appear in the AxiTrace dashboard after a test order | Plugin not enabled, or wrong public key | Check *Extensions → My extensions → AxiTrace → Configure*; verify the key starts with `pk_live_` or `pk_test_` |
| Orders appear but Facebook/TikTok show no conversions | Platform connection not configured in AxiTrace | Log in to [axitrace.com/dashboard](https://axitrace.com/dashboard) and verify your Facebook/TikTok destination is active |
| `Connection refused` or `cURL error` in `var/log/axitrace.log` | Outbound HTTPS blocked from your host | Allowlist `api.axitrace.com:443` on your firewall / WAF |
| Events duplicated in the ad platform | Client-side pixel AND server events both firing without deduplication | Ensure the AxiTrace JS snippet is present — it sets the `event_id` cookie that the server side reads for deduplication |
| Plugin not visible after install | Shopware plugin cache not cleared | `bin/console plugin:refresh && bin/console cache:clear` |

---

## Support

- **Documentation**: [axitrace.com/docs/integrations/shopware](https://axitrace.com/docs/integrations/shopware)
- **Issue tracker**: [github.com/axitrace/axitrace-shopware-plugin/issues](https://github.com/axitrace/axitrace-shopware-plugin/issues)
- **Email**: [info@axitrace.com](mailto:info@axitrace.com)

---

## License

MIT — see [LICENSE.md](./LICENSE.md).
