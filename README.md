# ShipStation ↔ Shopify Order Audit

Compares every paid Shopify order against ShipStation and surfaces the ones that are missing. Runs as a daily cron job and exposes a password-protected web dashboard to browse reports, run on-demand audits, spot-check individual orders, and bulk-manage exceptions.

---

## How it works

1. Fetches all Shopify orders in the configured date range (cursor-paginated, up to 250/page)
2. Fetches all ShipStation orders for the same range **plus a 7-day trailing buffer** (see [ShipStation fetch buffer](#shipstation-fetch-buffer))
3. Filters out orders that should never appear in ShipStation (see [What gets skipped](#what-gets-skipped))
4. For any order not found in ShipStation, checks whether it is on hold in Shopify (separate Fulfillment Orders API call, cached per order ID)
5. Diffs the two sets and flags any Shopify orders genuinely missing from ShipStation
6. Saves a CSV report under `reports/`
7. The web dashboard reads those reports and displays them

---

## Requirements

- PHP 8.3+ with the `curl` extension
- A web server (Apache / Nginx / Caddy) or `php -S` for local use
- ShipStation API credentials
- Shopify Admin API access token (requires `read_orders` and `read_fulfillments` scopes)

---

## Setup

### 1. Clone & configure

```bash
git clone https://github.com/mrgkanev/ShipStation-Shopify-Checker.git
cd ShipStation-Shopify-Checker
cp .env.example .env
```

Edit `.env` and fill in all required values:

| Variable | Required | Where to get it |
|---|---|---|
| `SS_API_KEY` | ✅ | ShipStation → Settings → API |
| `SS_API_SECRET` | ✅ | Same page |
| `SHOPIFY_STORE` | ✅ | The subdomain part of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify → Apps → Develop apps → your app → Admin API access token |
| `WEB_USERNAME` | - | Dashboard login username (default: `admin`) |
| `WEB_PASSWORD` | ✅ | Choose any password for the dashboard |
| `CACHE_TTL` | - | Cache duration in seconds (default: `82800` = 23 hours). Set to `0` to disable. |
| `APP_TITLE` | - | Browser tab title (default: `SS ↔ Shopify Audit`) |
| `APP_BRAND` | - | Sidebar and login logo text (default: `SS ↔ Shopify`) |
| `APP_LOGO` | - | URL to an image that replaces the brand text in the sidebar, header, and login page |
| `DEBUG` | - | Set to `1` to print full stack traces on errors |

#### Creating a Shopify access token

1. In Shopify Admin go to **Settings → Apps and sales channels → Develop apps**
2. Click **Create an app** and give it a name (e.g. "ShipStation Audit")
3. Go to **Configuration → Admin API integration → Edit**
4. Enable scopes: `read_orders`, `read_fulfillments`
5. Click **Save**, then **API credentials → Install app**
6. Copy the **Admin API access token** - it is shown only once

### 2. Point your web server at the repo

The `index.php` at the root is the dashboard entry point. Set your document root to the repo directory, or run locally:

```bash
php -S localhost:8080
# open http://localhost:8080
```

Log in with the username and password you set in `.env`. On first visit with no reports yet, click **Run first audit** to generate the initial report.

### 3. Run the audit manually (CLI)

```bash
php audit.php
```

Override the date window (default: last 90 days):

```bash
AUDIT_START_DATE=2025-01-01 AUDIT_END_DATE=2025-03-31 php audit.php
```

Spot-check specific order numbers without running a full audit:

```bash
php audit.php --spot-check 100042,100043
```

Exit codes: `0` = all clear, `1` = missing orders found, `2` = script error.

### 4. Schedule via cron

Run once a day at 06:00 and append output to a log file:

```cron
0 6 * * * cd /var/www/ShipStation-Shopify-Checker && php audit.php >> logs/audit.log 2>&1
```

---

## Dashboard pages

| Page | What it does |
|---|---|
| **Reports** | Browse historical CSV reports. Click any date in the sidebar to load that report. Download as CSV. Re-audit any past date with one click. |
| **Run Audit** | Run a live audit for any date range directly from the browser. Shows a progress indicator while fetching. Results are saved as a new report. |
| **Trends** | Aggregated stats across all reports: average missing count, worst day, repeat offenders. Includes a full history bar chart and a bulk-ignore table for chronic missing orders. |
| **Spot-check** | Look up one or more specific order numbers in ShipStation in real time. Found orders link directly to ShipStation. |
| **Ignored** | View and manage all ignored orders. Bulk-unignore with checkboxes. Import a list of order numbers to ignore via CSV upload. |
| **Push Log** | Full history of every order pushed to ShipStation from the dashboard: timestamp, order number, status, and links to both platforms. Filterable by status. |
| **Settings** | Test API connectivity for both platforms, view current `.env` configuration. |

### Missing order actions

Each missing order row has:
- A **Shopify admin link** to the order
- A **Search SS** link that opens ShipStation filtered to that order number
- A **Type** chip classifying the order (e.g. `Pro`, `Bundle`) based on line-item rules - see [Order type classification](#order-type-classification)
- A **Seen** badge showing how many reports the order has appeared in (`2×` = yellow, `3×+` = red)
- An **Ignore** button to permanently exclude it from future reports (with optional reason)
- A **checkbox** for selecting multiple orders to bulk-ignore in one action
- A **Preview** button that builds the ShipStation payload and shows it in a modal without sending - useful for verifying the data before pushing
- A **Push to SS** button that creates the order in ShipStation directly from the dashboard (fetches full order detail from Shopify and posts it to the ShipStation `createorder` endpoint)
- A **Re-check** link that pre-fills the Spot-check input with that order number for a quick live lookup

---

## What gets skipped

The following Shopify orders are intentionally excluded from the comparison.

| Skip reason | Condition | Why |
|---|---|---|
| `cancelled` | `cancelled_at` is set | Order was cancelled - never goes to ShipStation |
| `financial` | `financial_status` is `pending`, `voided`, `refunded`, or `partially_refunded` | Unpaid, reversed, or refunded - not actionable |
| `fulfilled` | `fulfillment_status` is `fulfilled` | All items have been shipped; order is complete |
| `restocked` | `fulfillment_status` is `restocked` | Items returned and restocked after shipment |
| `on_hold` | Any fulfillment order has `status: on_hold` | Fulfillment deliberately paused - not a missing order |
| `zero_value` | `total_price == 0` | Digital downloads, gift cards, fully-discounted orders |
| `no_shipping` | `shipping_lines` is empty | No physical shipment needed |
| `ignored` | Manually dismissed via the dashboard | One-off exceptions added by the team |

> **Note on `on_hold`:** Shopify does not expose hold status on the order object itself - it lives on the Fulfillment Order level and requires a separate API call (`/orders/{id}/fulfillment_orders.json`). This check runs only for orders already flagged as missing. Results are cached per order ID to avoid redundant calls on re-runs.

---

## Order type classification

Orders can be automatically classified into named types based on their line items. This label appears as a coloured chip in the missing-orders table and is included as an `order_type` column in exported CSV reports.

### Setup

Copy the example file and define your own rules:

```bash
cp order_types.example.json order_types.json
```

Edit `order_types.json`:

```json
{
  "fallback": "Accessory",
  "rules": [
    { "name": "Pro",    "match": "sku_starts_with", "value": "widget-pro-" },
    { "name": "Bundle", "match": "title_contains",  "value": "starter kit" },
    { "name": "OEM",    "match": "vendor_is",        "value": "Acme Corp" }
  ]
}
```

Rules are checked against every line item in the order. If any line item matches, that type label is applied. Multiple matched types are joined with ` + ` (e.g. `Pro + Bundle`). Orders with no matching rules receive the `fallback` label.

### Available match types

| Match type | Behaviour |
|---|---|
| `sku_starts_with` | SKU starts with the given string (case-insensitive) |
| `sku_contains` | SKU contains the given string (case-insensitive) |
| `sku_not_starts_with` | SKU does **not** start with any of the given strings - pass an array for multiple prefixes |
| `title_contains` | Product title contains the given string (case-insensitive) |
| `vendor_is` | Product vendor exactly matches the given string (case-insensitive) |

> Classification requires line items to be fetched from Shopify. This happens automatically during audits - no extra configuration needed.

---

## Matching fallback - email + amount

In addition to order-number matching, the comparator builds a secondary ShipStation index keyed by `email + rounded amount`. If an order number lookup fails but a ShipStation order exists with the same customer email and a total within 1 % of the Shopify order total, it is treated as a match. This catches manually-entered ShipStation orders where the order number was typed differently.

---

## ShipStation fetch buffer

When ShipStation order numbers use compound formats such as `100042-B2` or `Addon-100031`, two problems arise.

**Problem 1 - normalisation mismatch.**
The original normalisation stripped all non-digit characters and joined the remaining digits together. A number like `100042-B2` would therefore become `1000422` (the trailing `2` from `B2` glues onto the main number), which never matches the Shopify order `100042`.

The fix: the ShipStation index is now built by extracting **every contiguous digit-run** in the order number separately and indexing the order under each one. `100042-B2` is indexed under both `100042` and `2`; `Addon-100031` is indexed under `100031`. Because no real Shopify order number is a single digit, the short segments are harmless.

**Problem 2 - date window gap.**
Sub-orders (Addon, variant suffixes, etc.) are often created in ShipStation one or more days after the original Shopify order. If the audit window ends on day D and an Addon order is entered into ShipStation on day D+2, it falls outside the fetch window and is invisible to the index - causing a false "missing" result.

The fix: ShipStation orders are now fetched for `startDate` → `endDate + 7 days`. Shopify orders are still fetched for the exact window, so the comparison logic is unaffected - the extra SS orders simply sit in the index unused. Seven days was chosen as a conservative buffer; if sub-orders in your workflow are created more than a week after the Shopify order, increase the offset in `audit.php` and `index.php`.

---

## Caching

API responses are cached under `cache/` as JSON files keyed by platform and date range. The default TTL is 23 hours (`CACHE_TTL=82800`) - aligned with the daily cron schedule so each run fetches fresh data. Repeated runs within the same day reuse the cache automatically.

The ShipStation cache key includes the extended end date (`endDate + 7 days`), so changing the buffer automatically invalidates the old cache.

The ShipStation cache expiry marker (`expires_at`) uses the same `CACHE_TTL` value configured in `.env`, keeping the meta header consistent with the actual cache behaviour.

To force a fresh fetch: use **Clear all cache** in the Run Audit page, or set `CACHE_TTL=0` in `.env`.

---

## Security

- The web dashboard is protected by username/password stored in `.env`. Sessions are managed by PHP's native session mechanism.
- Login attempts are rate-limited: 3 failed attempts per IP triggers a 1-week lockout. The lockout state is stored in `data/login_attempts.json`. Admins can view and remove active bans from the **Settings** page.
- All user-supplied values rendered in HTML are escaped with `htmlspecialchars`.
- The `reports/`, `cache/`, `data/`, and `logs/` directories should not be web-accessible. Add the following to your server config or `.htaccess` if your document root is the repo root:

```apache
<DirectoryMatch "^/var/www/ShipStation-Shopify-Checker/(reports|cache|data|logs)/">
    Require all denied
</DirectoryMatch>
```

---

## Dark mode

The dashboard automatically follows the OS preference (`prefers-color-scheme`). There is no manual toggle - dark mode is always on when the system is set to dark.
