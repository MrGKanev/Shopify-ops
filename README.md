# Shopify Ops

A self-hosted Shopify operations toolkit. Password-protected web dashboard with tools for order auditing, metafield inspection, tag-based search, and duplicate detection. Runs on plain PHP — no framework, no build step.

---

## Tools

| Page | What it does |
|---|---|
| **Reports** | Browse historical ShipStation sync reports. Click any date in the sidebar to load that report. Download as CSV. |
| **Run Audit** | Compare every paid Shopify order against ShipStation for any date range. Flags genuinely missing orders and surfaces potential duplicate purchases. |
| **Trends** | Aggregated stats across all reports: average missing count, worst day, repeat offenders. Includes a full history chart and bulk-ignore table. |
| **Spot-check** | Look up one or more specific order numbers live in ShipStation and/or Shopify. |
| **Metafields** | Browse order metafield definitions. Search orders by metafield value. Look up all metafields on a specific order. |
| **Tag Search** | Find all Shopify orders with a specific tag — fast, native index, no full scan needed. |
| **Ignored** | View and manage all ignored orders. Bulk-unignore with checkboxes. Import via CSV. |
| **Push Log** | Full history of every order pushed to ShipStation from the dashboard. |
| **Settings** | Test API connectivity, view current `.env` config, manage banned IPs. |

---

## How the audit works

1. Fetches all Shopify orders in the configured date range (cursor-paginated, up to 250/page)
2. Fetches all ShipStation orders for the same range **plus a 7-day trailing buffer** (catches sub-orders entered a few days after the Shopify order)
3. Filters out orders that should never appear in ShipStation (see [What gets skipped](#what-gets-skipped))
4. For any order not found in ShipStation, checks whether it is on hold in Shopify (cached per order ID)
5. Diffs the two sets and flags genuinely missing orders
6. Saves a CSV report under `reports/`
7. Scans the fetched Shopify orders for potential duplicates (same email + amount within 24 h)

---

## Requirements

- PHP 8.3+ with the `curl` extension
- A web server (Apache / Nginx / Caddy) or `php -S` for local use
- ShipStation API credentials
- Shopify Admin API access token (`read_orders`, `read_fulfillments`, `read_metaobjects` scopes)

---

## Setup

### 1. Clone & configure

```bash
git clone https://github.com/mrgkanev/ShipStation-Shopify-Checker.git
cd ShipStation-Shopify-Checker
cp .env.example .env
```

Edit `.env`:

| Variable | Required | Notes |
|---|---|---|
| `SHOPIFY_STORE` | ✅ | Subdomain of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify Admin API access token |
| `SS_API_KEY` | ✅ | ShipStation → Settings → API |
| `SS_API_SECRET` | ✅ | Same page |
| `WEB_PASSWORD` | ✅ | Dashboard login password |
| `WEB_USERNAME` | — | Login username (default: `admin`) |
| `CACHE_TTL` | — | Cache duration in seconds (default: `82800` = 23 h). Set to `0` to disable. |
| `APP_TITLE` | — | Browser tab title (default: `Shopify Ops`) |
| `APP_BRAND` | — | Sidebar / login text (default: `Shopify Ops`) |
| `APP_LOGO` | — | URL to an image that replaces the brand text |
| `APP_STORE_NUMBER` | — | ShipStation store number — shown as subtitle on login and in the browser tab |

#### Creating a Shopify access token

1. Shopify Admin → **Settings → Apps and sales channels → Develop apps**
2. **Create an app**, then **Configuration → Admin API integration → Edit**
3. Enable scopes: `read_orders`, `read_fulfillments`, `read_metaobjects`
4. **Save** → **API credentials → Install app**
5. Copy the **Admin API access token** — shown only once

### 2. Run locally

```bash
php -S localhost:8080
# open http://localhost:8080
```

### 3. Run the audit via CLI

```bash
php audit.php
```

Override the date window (default: last 90 days):

```bash
AUDIT_START_DATE=2025-01-01 AUDIT_END_DATE=2025-03-31 php audit.php
```

Exit codes: `0` = all clear, `1` = missing orders found, `2` = script error.

### 4. Schedule via cron

```cron
0 6 * * * cd /var/www/shopify-ops && php audit.php >> logs/audit.log 2>&1
```

---

## What gets skipped

| Skip reason | Condition |
|---|---|
| `cancelled` | `cancelled_at` is set |
| `financial` | Status is `pending`, `voided`, `refunded`, or `partially_refunded` |
| `fulfilled` | `fulfillment_status` is `fulfilled` or `restocked` |
| `on_hold` | Any fulfillment order has `status: on_hold` |
| `zero_value` | `total_price == 0` |
| `no_shipping` | `shipping_lines` is empty |
| `ignored` | Manually dismissed via the dashboard |

> **`on_hold`** is not exposed on the order object — it lives on the Fulfillment Order level and requires a separate API call, cached per order ID.

---

## Duplicate detection

After each audit, Shopify orders are scanned for potential duplicates: same customer email + same rounded total, placed within 24 hours of each other. Results appear as a collapsible section below the missing-orders table. Useful for catching double-checkouts or accidental repeat purchases before they ship.

---

## Metafields

The **Metafields** page has three sections:

- **Definitions** — lists all order metafield definitions from the store (via GraphQL)
- **Search by value** — paginate through orders in a date range and filter by metafield value client-side. Leave Value empty to see all orders that have a given metafield set.
- **Lookup by order number** — fetch all metafields for one or more specific orders

---

## Tag Search

The **Tag Search** page uses Shopify's native tag index (`tag:"value"` in the GraphQL query string) to instantly find all orders with a given tag, optionally filtered by date range. No full scan needed.

---

## Order type classification

Orders are automatically classified into named types based on line items. The label appears as a coloured chip in the missing-orders table and in exported CSV reports.

```bash
cp order_types.example.json order_types.json
```

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

| Match type | Behaviour |
|---|---|
| `sku_starts_with` | SKU starts with the given string (case-insensitive) |
| `sku_contains` | SKU contains the given string (case-insensitive) |
| `sku_not_starts_with` | SKU does **not** start with any of the given prefixes |
| `title_contains` | Product title contains the given string (case-insensitive) |
| `vendor_is` | Product vendor exactly matches the given string (case-insensitive) |

---

## Matching fallback

In addition to order-number matching, a secondary ShipStation index is keyed by `email + rounded amount`. If an order number lookup fails but a ShipStation order exists with the same customer email and a total within 1% of the Shopify total, it is treated as a match. Catches manually-entered ShipStation orders where the order number was typed differently.

---

## ShipStation fetch buffer

ShipStation orders are fetched for `startDate → endDate + 7 days`. Sub-orders (Addon, variant suffixes, etc.) are often entered into ShipStation a few days after the original Shopify order — the buffer prevents false "missing" results. Shopify orders are still fetched for the exact window, so comparison logic is unaffected.

The ShipStation order number index extracts every contiguous digit-run separately, so compound formats like `100042-B2` or `Addon-100031` resolve to their Shopify counterpart correctly.

---

## Caching

API responses are cached under `cache/` as JSON files keyed by platform and date range. Default TTL: 23 hours (`CACHE_TTL=82800`). Repeated runs within the same day reuse the cache automatically.

To force a fresh fetch: **Clear all cache** in the Run Audit page, or set `CACHE_TTL=0` in `.env`.

---

## Security

- Username/password authentication stored in `.env`
- 3 failed login attempts per IP triggers a 1-week lockout (manageable from Settings)
- All user-supplied values escaped with `htmlspecialchars`
- Protect data directories from direct web access:

```apache
<DirectoryMatch "^/var/www/shopify-ops/(reports|cache|data|logs)/">
    Require all denied
</DirectoryMatch>
```
