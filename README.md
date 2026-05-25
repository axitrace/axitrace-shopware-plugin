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
6. **Save** the configuration.
7. **Place a test order** in your storefront. Within 1–2 minutes the AxiTrace
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

The plugin respects your store's cookie consent configuration:

- If a visitor has not given marketing consent, the AxiTrace JavaScript SDK
  will not fire client-side events.
- Server-side `purchase` events are forwarded regardless of consent (they
  contain no browser-session PII beyond what the customer explicitly provided
  at checkout). Adjust this behaviour via the *Require consent for server-side
  events* toggle in the plugin configuration if your legal counsel advises it.

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
