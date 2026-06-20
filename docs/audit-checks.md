# Audit Checks

Individual audit pages that surface specific order and product issues. All pages support CSV export unless noted otherwise.

---

## Fulfillment & Logistics

### Partial Fulfillment Stalls
Finds paid Shopify orders in `partial` fulfillment status where unfulfilled items haven't progressed. "Stalled for" counts days since the last fulfillment was created (or from order date if no fulfillment exists at all). Excludes cancelled, fully refunded, and closed orders.

- Configurable stall threshold in days
- Color-coded: red ≥ 30 days, yellow ≥ 14 days
- Shows unfulfilled line items with SKUs and quantities

### Fulfillment SLA Breaches
Finds paid Shopify orders whose time-to-first-fulfillment exceeds a configurable threshold. Fulfilled orders are measured from placement to first fulfillment; open orders are measured from placement to today.

- Groups context by shipping method, destination region, and configured order type
- Useful for finding slow lanes, regions, or product types

### Shipment Aging
Scans the live ShipStation awaiting-shipment queue and flags orders older than N days.

- Includes SKU and order-type summaries
- Links directly to ShipStation and Spot-check

### Voided Shipments
Shows shipments voided in ShipStation within the selected date range. Intended for proactive follow-up before the customer notices a tracking link has gone dead.

- Displays: order#, void date, original ship date, carrier, service, tracking number, ship-to address

### Address Changes
Uses Shopify order events via GraphQL to find orders where the shipping address was edited after placement. Only surfaces orders with an explicit "shipping address updated" event - not just any edit.

- Useful for catching last-minute requests, fraudulent address swaps, or support edits that didn't reach ShipStation
- Large date ranges are slower due to order event pagination
- Shows time gap between placement and change

### Order Edit History
Uses Shopify order events via GraphQL to detect post-placement edits to line items, discounts, notes, or custom attributes. Distinct from Address Changes (which is tracked separately).

- Edit summary shows the actual event messages Shopify logged
- Time gap color-coded: red ≥ 1 day, yellow ≥ 1 hour
- In-table search/filter by order#, email

---

## Fraud & Compliance

### Country Mismatch
Finds paid orders where the billing country differs from the shipping country - a documented Shopify fraud signal. Common in freight forwarding, stolen card abuse, and drop-ship fraud. Most matches are legitimate, but outliers are worth reviewing manually.

- In-table search by order#, email, country

### High-Value No Phone
Surfaces paid, unfulfilled orders above a configurable dollar threshold where the shipping address has no phone number. Carriers increasingly require a phone number for high-value shipments; catching this before dispatch avoids delivery delays.

### Address Scanner
Validates shipping addresses on all paid/partially paid orders in the date range.

| Severity | Checks |
| --- | --- |
| **Critical** | Missing street, city, ZIP, country, or recipient name |
| **Warning** | Invalid ZIP format (US/CA), missing state/province, PO Box, no phone on express shipment |

Filters: PO Box only, unfulfilled only. Critical issues sorted to top.

### Email Checker
Scans paid/partially paid orders for email issues before they ship.

| Severity | Checks |
| --- | --- |
| **Critical** | Missing/invalid email, known disposable domains (Mailinator, YOPmail, 10MinuteMail, etc.) |
| **Warning** | Placeholder-like addresses (`test@`, `noemail@`), very short local parts, suspicious repeated characters |

### Repeat Refunds
Identifies customers with 2+ refunded orders in the selected date range. Configurable minimum refund count (default ≥ 2). Groups by email, sorted by refund count descending. Each customer links to their full Customer Lookup page.

---

## Order Quality

### Orphan Detector
Reverse audit: finds ShipStation orders with no matching Shopify order. Common causes include manually created SS orders, test/dummy entries, orders imported from other channels (Amazon, eBay, CSV), or a disconnected Shopify store.

- Matching uses normalized order numbers (same logic as the main audit engine)

### Active SS Conflicts
Finds Shopify orders that are refunded or cancelled but still active in ShipStation (`awaiting_payment`, `awaiting_shipment`, or `on_hold`).

- Prevents accidental fulfillment after refund/cancellation
- Uses the same normalized order matching as the audit engine

### Bundle Check
Scans for orders missing required companion items as defined in `order_types.json` under `required_items`. Covers fulfilled orders too - catching shipped bundles missing a component is the most urgent case. See [order-types.md](order-types.md) for configuration.

---

## Product & Catalogue

### Product Completeness
Scans all active products for missing content that breaks fulfillment or affects storefront quality.

| Severity | Check |
| --- | --- |
| **Critical** | Variants with no SKU |
| **Warning** | No images, no description |

Links directly to the Shopify product editor for quick fixes.

### SKU Duplicates
Scans all products (active, draft, archived) for SKUs appearing more than once. Variants with no SKU are ignored. Duplicate SKUs cause inventory tracking errors and fulfillment routing issues.

### Inventory Oversell Risk
Compares current Shopify inventory levels against ShipStation orders awaiting shipment. Only considers variants where Shopify inventory management is enabled with a `deny` policy. Shows the shortfall when awaiting quantity exceeds available stock.

- Real-time check (no date range needed)

### Inventory Aging
Finds active tracked variants at zero or negative inventory that still sold in the selected recent window.

- Helps identify stale stock settings, inventory sync issues, or demand that needs replenishment

### Discount Abuse
Groups paid orders by discount code and shipping address, then flags clusters where multiple distinct customer emails used the same code at the same destination.

### Tag Policy Audit
Validates paid orders against `tag_policy.json`.

- `required`: when all trigger tags are present, required tags must also be present
- `forbidden`: listed tags must not appear together on one order
