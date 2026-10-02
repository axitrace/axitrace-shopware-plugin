# AxiTrace for Shopware 6

Server-side tracking plugin for Shopware 6 stores. Forwards order and commerce
events to AxiTrace, which relays them to Facebook CAPI, TikTok Events API,
Google Ads offline conversions, and GA4 - server-side, with deterministic event
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
- bypassing ad blockers and iOS 14+ restrictions that degrade client-side
pixels.

Key benefits:

- **Higher match rates** - server-to-server requests carry more signals than
  browser pixels blocked by extensions or Safari ITP.
- **Deduplication** - each event carries a stable UUID so the same conversion
  is never counted twice across server + client channels.
- **One dashboard** - all platforms in a single AxiTrace workspace; no need to
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
6. *(Optional)* Choose the **Conversion value** basis - which order amount is
   reported as the purchase value to Facebook, Google Ads, GA4, TikTok and
   Reddit. Default is the order total incl. VAT and shipping; you can exclude
   shipping and/or VAT (e.g. *Product revenue only - excl. VAT and shipping*
   for margin-based bidding). The setting applies to new orders only; the
   gross VAT and shipping amounts are always sent alongside for reference.
7. *(Optional)* Choose a **Pinterest catalog product ID** mode when your
   Pinterest catalog uses Shopware product numbers. The default keeps the
   existing identifiers. The lowercase option is useful for feeds that
   normalize product numbers to lowercase. This affects Pinterest only.
8. *(Optional, for profit tracking)* Paste your workspace **secret key**
   (`sk_live_...`) into the *AxiTrace secret key* field. In AxiTrace it is
   under **Settings**, in the **Container Information** card, as **Secret Key**.
   With it the plugin
   sends each order line's net purchase price (the product's *purchase price*
   in the order currency; a variant without one uses its parent's) and reports
   refunds and cancellations, so AxiTrace can show profit and POAS. The key is
   used server-to-server only and is never placed in the storefront. If
   AxiTrace rejects the key, purchases are still sent, without costs, and the
   rejection is logged critical. Leave it empty and the plugin sends neither
   costs nor refunds.
9. **Save** the configuration.
10. **Place a test order** in your storefront. Within 1-2 minutes the AxiTrace
   dashboard should show the order on the events feed.

---

## Events Captured

| Event | Trigger |
|-------|---------|
| `purchase` | Shopware `OrderStateMachineStateChangeEvent` fires when an order transitions to the `paid` state. Idempotent via the `axitrace_failed_event_log` unique constraint. |
| refund | Secret key only. A payment transaction enters `refunded` or `refunded_partially`. Amounts come from the refunds a payment integration recorded in Shopware, or the whole transaction amount for a full refund. A partial refund with no recorded amount is logged and not sent. |
| cancellation | Secret key only. A paid order enters `cancelled`, or a paid payment is cancelled. Sends what has not already been refunded. Reduces profit and POAS; ROAS and the purchase already sent to the ad platforms stay unchanged. |

The plugin loads the AxiTrace browser SDK and captures ViewContent, AddToCart,
InitiateCheckout and AddPaymentInfo. Product and cart data come from Shopware's
server-authoritative storefront context. Purchase remains server-only and is
sent when the order transaction becomes paid.

PII (email, phone) is forwarded in **plain text** server-to-server; AxiTrace
hashes it internally per each platform's requirements before transmission.
Browser checkout identity is returned only after an explicit consent grant and
is never embedded in cacheable storefront HTML.

---

## Cookie Consent

Where the decision is made: **your AxiTrace workspace**
(*Workspace -> Domains -> Cookie consent -> Respect cookie consent*). It is off
by default. While it is on, the browser SDK writes nothing and sends nothing
until the shopper allows marketing cookies - on every Sales Channel, including
one left on *Load immediately* and a store with no cookie banner at all. The SDK
asks AxiTrace for that policy before it boots, so a change made in the admin
panel reaches the storefront within 5 minutes and needs no plugin change.

The plugin's **Cookie consent mode** setting (per Sales Channel,
*Extensions -> My extensions -> AxiTrace Tracking -> Configure -> Cookie consent*)
is an **extra** condition on top of the workspace policy: a Sales Channel can
tighten it, never loosen it.

### The three modes

| Mode | Browser SDK | Server-side order events |
|---|---|---|
| **Load immediately** *(default)* | loads at once; the AxiTrace workspace policy still applies | always sent |
| **Wait for consent - browser tracking only** | not loaded until a grant signal | always sent |
| **Wait for consent - browser tracking and server-side order events** | not loaded until a grant signal | sent only when the shopper consented |

The default keeps the plugin's own behaviour from every release before 0.2.0:
the plugin gates nothing until you opt in. Changes apply to **orders placed
after saving**.

### How a consent grant is detected (all paths always active while gating is on)

1. **Shopware's own cookie consent manager** - the plugin registers an
   `axitrace-enabled` master entry in its AxiTrace cookie group; accepting the
   group sets `axitrace-enabled=1` and boots the SDK without a reload.
2. **Any consent cookie you configure** - enter the cookie name your CMP sets
   on accept (Acris, Usercentrics, Cookiebot, CCM19, …) under *Consent cookie
   name*. A bounded poll picks the cookie up within ~500 ms.
3. **The JavaScript API** - the universal escape hatch. One line from any CMP's
   "on accept" callback:

```js
window.axitraceConsent && window.axitraceConsent.grant();
```

With both workspace consent checks and plugin gating off, customer enrichment
does not require a consent cookie (plugin 0.4.2 and SDK 0.21.2).

When consent checks are enabled, the JavaScript call alone allows tracking, but it does not expose
checkout customer fields. Email and address match fields are returned only when
the configured consent cookie is also present and valid. CMP integrations that
need those fields must set that cookie as part of their accept action before
calling `grant()`.

In a *Wait for consent* mode the SDK is not loaded at all until a grant signal
arrives - no cookie, no localStorage entry, no network request.
`window.axitraceConsent.isGranted()` reports the current state.

### Withdrawal

A shopper may take their consent back on the same page, and that is honoured in
**every mode**, *Load immediately* included. Either Shopware's own
`CookieConfiguration_Update` stops carrying the AxiTrace cookie, or your CMP
calls one line from its withdrawal callback:

```js
window.axitraceConsent && window.axitraceConsent.revoke();
```

`revoke()` stops the SDK and deletes the identifiers the grant allowed it to
write (`vt_vid`, `vt_sid`, `vt_uid`, their storage mirrors and the queued
events). Cookies belonging to other tools, such as Meta's `_fbp` and `_fbc`, are
left alone - they are not ours to delete. A later grant starts a **new** visitor
rather than restoring the previous one.

### What the order reports

Every mode records the shopper's decision on the order and sends it to AxiTrace
(`data.consent`), so the workspace forwarding policy can act on a Shopware
purchase too:

- an AxiTrace consent cookie present at order placement is reported as
  `granted`;
- Shopware's own cookie-preference marker proving the shopper answered the
  banner, with no AxiTrace consent cookie, is reported as `denied`;
- **neither of the two: the order carries no consent key at all.** A store with
  no banner, a shopper who has not answered yet and an order created outside a
  storefront session all look identical from the server, so the plugin never
  invents a refusal. The AxiTrace setting *Forward events that do not report a
  consent state* decides what happens to such a purchase.

Browser events carry the same decision as `meta.consent` once the SDK is
running.

### Fail-closed for orders without a storefront session

In the strictest mode, orders created without a storefront session (admin
orders, imports, API orders) carry no consent record and are therefore **not
forwarded** - an order with no recorded consent decision is not treated as
granted. Every such skip is logged at `warning`. If you regularly create orders
outside the storefront, use *Wait for consent - browser tracking only* instead.

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
| Upper-funnel events duplicated in the ad platform | The browser pixel and AxiTrace server forwarding use different integrations | Let the AxiTrace SDK coordinate both legs; it sends the same per-event ID to the browser tag and the ingestion API. Shopware Purchase is server-only and fires on the paid transition. |
| Plugin not visible after install | Shopware plugin cache not cleared | `bin/console plugin:refresh && bin/console cache:clear` |

---

## Support

- **Documentation**: [axitrace.com/docs/integrations/shopware](https://axitrace.com/docs/integrations/shopware)
- **Issue tracker**: [github.com/axitrace/axitrace-shopware-plugin/issues](https://github.com/axitrace/axitrace-shopware-plugin/issues)
- **Email**: [info@axitrace.com](mailto:info@axitrace.com)

---

## License

MIT - see [LICENSE.md](./LICENSE.md).
