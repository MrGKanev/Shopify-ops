# Shopify Ops

A self-hosted Shopify operations toolkit. Audits and surfaces Shopify order issues, provides search and lookup tools, and optionally syncs with ShipStation for order matching and push. Runs on plain PHP - no framework, no build step.

> **Shopify is the only required integration.** Most pages work with a Shopify access token alone. ShipStation credentials are optional — needed only for the audit engine, push log, and order matching features.

---

## Tools

| Page | What it does |
| --- | --- |
| **Reports** | Browse historical ShipStation sync reports. Click any date in the sidebar to load that report. Download as CSV. |
| **Run Audit** | Compare every paid Shopify order against ShipStation for any date range. Flags genuinely missing orders and surfaces potential duplicate purchases. |
| **Trends** | Aggregated stats across all reports: average missing count, worst day, repeat offenders. Includes a full history chart and bulk-ignore table. |
| **Duplicate Detector** | Scan for potential duplicate purchases (same email + amount within 24 h). |
| **Refunds Tracker** | Track refunded orders across a date range. |
| **Address Scanner** | Flag orders with potentially undeliverable or mismatched shipping addresses. |
| **Email Checker** | Scan for orders with missing or invalid customer emails. |
| **Orphan Detector** | Find Shopify orders that have no matching ShipStation record and are not skipped by any rule. |
| **High-Value No Phone** | Surface high-value orders missing a phone number. |
| **Repeat Refunds** | Identify customers with multiple refunds. |
| **Voided Shipments** | Orders where a shipment was voided/cancelled after creation. |
| **Address Changes** | Orders where the shipping address was edited after placement. |
| **Order Edit History** | Full edit history for a given order. |
| **Bundle Check** | Validate that bundle orders contain all expected line items. |
| **Product Completeness** | Flag products missing images, descriptions, or other required fields. |
| **SKU Duplicates** | Detect products sharing the same SKU across the catalogue. |
| **Inventory Oversell Risk** | Surface variants at risk of overselling based on current stock. |
| **Billing ≠ Shipping Country** | Flag orders where billing and shipping countries differ. |
| **Partial Fulfillment Stalls** | Orders partially fulfilled but with no further activity. |
| **Spot-check** | Look up one or more specific order numbers live in ShipStation and/or Shopify. |
| **Metafields** | Browse order metafield definitions. Search orders by metafield value. Look up all metafields on a specific order. |
| **Tag Search** | Find all Shopify orders with a specific tag - fast, native index, no full scan needed. |
| **Tag Audit** | Audit tag usage across orders. |
| **Customer Lookup** | Look up a customer and their full order history. |
| **Tracking Feed** | Live tracking status for recent shipments. |
| **Order Compare** | Side-by-side comparison of two orders. |
| **Order Timeline** | Visual timeline of all events on a given order. |
| **Global Search** | Search across orders by number, email, or name. |
| **Packing Slip Preview** | Generate a packing slip preview for any order. |
| **Ignored** | View and manage all ignored orders. Bulk-unignore with checkboxes. Import via CSV. |
| **Push Log** | Full history of every order pushed to ShipStation from the dashboard. |
| **Settings** | Test API connectivity, view current `.env` config, manage banned IPs. |

---

## Requirements

- PHP 8.3+ with the `curl` extension
- A web server (Apache / Nginx / Caddy) or `php -S` for local use
- Shopify Admin API access token (`read_orders`, `read_fulfillments`, `read_metaobjects` scopes)
- ShipStation API credentials _(optional — audit and push features only)_

---

## Setup

### 1. Clone & configure

```bash
git clone https://github.com/MrGKanev/Shopify-ops.git
cd Shopify-ops/
cp .env.example .env
```

Edit `.env` with your credentials. See [docs/configuration.md](docs/configuration.md) for all available variables.

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

## Further reading

- [Audit engine — how it works, skip rules, duplicate detection](docs/audit.md)
- [Audit checks — address, email, fraud, product, inventory checks](docs/audit-checks.md)
- [Search & Lookup — spot-check, timeline, metafields, tags, customer](docs/search-lookup.md)
- [Order type classification — rules, JSON config, required items](docs/order-types.md)
- [Configuration — all ENV vars, caching, security](docs/configuration.md)
