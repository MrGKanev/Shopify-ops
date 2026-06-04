# All Tools

## Audit

| Page | What it does |
| --- | --- |
| **Reports** | Browse historical ShipStation sync reports. Click any date in the sidebar to load that report. Download as CSV. |
| **Run Audit** | Compare every paid Shopify order against ShipStation for any date range. Flags genuinely missing orders and surfaces potential duplicate purchases. |
| **Trends** | Aggregated stats across all reports: average missing count, worst day, repeat offenders. Includes a full history chart and bulk-ignore table. |
| **Duplicate Detector** | Scan for potential duplicate purchases (same email + amount within 24 h). |
| **Refunds Tracker** | Track refunded orders across a date range. |
| **Address Scanner** | Flag orders with potentially undeliverable or mismatched shipping addresses. |
| **Email Checker** | Scan for orders with missing or invalid customer emails. |
| **Orphan Detector** | Find ShipStation orders with no matching Shopify order. |
| **High-Value No Phone** | Surface high-value unfulfilled orders missing a shipping phone number. |
| **Repeat Refunds** | Identify customers with multiple refunded orders. |
| **Voided Shipments** | Orders where a shipment was voided/cancelled after creation. |
| **Address Changes** | Orders where the shipping address was edited after placement. |
| **Order Edit History** | Post-placement edits to line items, discounts, notes, or custom attributes. |
| **Bundle Check** | Validate that bundle orders contain all expected companion items. |
| **Product Completeness** | Flag active products missing images, descriptions, or variant SKUs. |
| **SKU Duplicates** | Detect variants sharing the same SKU across the catalogue. |
| **Inventory Oversell Risk** | Surface variants where ShipStation awaiting qty exceeds available Shopify stock. |
| **Billing ≠ Shipping Country** | Flag orders where billing and shipping countries differ — a documented fraud signal. |
| **Partial Fulfillment Stalls** | Open orders partially shipped with unfulfilled items stalled for N+ days. |

## Search & Lookup

| Page | What it does |
| --- | --- |
| **Spot-check** | Live lookup of 1–50 order numbers in ShipStation and/or Shopify simultaneously. |
| **Metafields** | Browse metafield definitions, search orders by metafield value, or look up all metafields on a specific order. |
| **Tag Search** | Find all Shopify orders with a specific tag — fast, native index, no full scan. |
| **Tag Audit** | Build a complete tag inventory across a date range with frequency and last-seen info. |
| **Customer Lookup** | Full order history for a customer by email, with lifetime spend summary and CSV export. |
| **Tracking Feed** | Live tracking details for 1–30 orders with direct links to carrier tracking pages. |
| **Order Compare** | Side-by-side comparison of two Shopify orders with differing fields highlighted. |
| **Order Timeline** | Merged Shopify + ShipStation event timeline for a single order. |
| **Global Search** | Search order number across audit reports, push log, and ignored orders at once. |
| **Packing Slip Preview** | Fetch and render a ShipStation packing slip for any order. Print-optimised. |

## Manage

| Page | What it does |
| --- | --- |
| **Ignored** | View and manage all ignored orders. Bulk-unignore with checkboxes. Import via CSV. |
| **Push Log** | Full history of every order pushed to ShipStation from the dashboard. |
| **Settings** | Test API connectivity, view current `.env` config, manage banned IPs. |
