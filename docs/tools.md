# All Tools

## Audit

_Generated from the tool registry in [`config/reports.php`](../config/reports.php), which also drives the report routes, the audit navigation and the notification tool catalog. Edit the registry and run `php artisan docs:tools` — do not edit the section between the markers by hand._

<!-- AUTO-GENERATED:AUDIT-SECTION:START -->

### Core Audit

| Page | What it does |
| --- | --- |
| **Saved Reports** | View and download saved audit reports |
| **Run Audit** | Compare Shopify vs ShipStation for any date range |
| **Operational Digest** | Paid pending orders, sync findings, unresolved issues and upcoming fulfillment deadlines |
| **Rate Shopping Audit** | Captured quote decisions and clearly labelled current-rate simulations under approved service conditions |
| **Trends** | Aggregated stats across all audit reports |

### Order Issues

| Page | What it does |
| --- | --- |
| **Duplicate Detector** | Same customer, same total - placed within 10 minutes |
| **Refunds Tracker** | Refunded Shopify orders cross-checked against ShipStation |
| **Repeat Refunds** | Customers with multiple refunded orders in a date range |
| **Return / RMA Tracker** | Overdue return requests, received items awaiting processing and unfinished exchanges |
| **Returned Items Report** | Itemized quantity totals for refunded line items in a date range |
| **Orphan Detector** | ShipStation orders with no matching Shopify order |
| **Active SS Conflicts** | Refunded or cancelled Shopify orders still active in ShipStation |
| **SS Shipped / Shopify Unfulfilled** | ShipStation shipped orders that Shopify still shows as unfulfilled (sync failure) |
| **Order Edit History** | Orders with post-placement edits: line items, discounts, notes or custom attributes |
| **Note Flags** | Paid unfulfilled orders with flagged keywords in the order note |

### Address & Contact

| Page | What it does |
| --- | --- |
| **Address Scanner** | Paid orders with incomplete or invalid shipping addresses — required fields and postal code formats per country, plus phone validation for the shipping country |
| **Email Checker** | Orders with invalid, disposable or suspicious emails |
| **High-Value No Phone** | High-value unfulfilled orders whose shipping phone is missing or not valid for the shipping country |
| **Address Changes** | Orders whose shipping address was edited after placement |
| **Post-Ship Address Change** | Address edited AFTER the order was already fulfilled - package already in transit |
| **Duplicate Shipping Addresses** | Different customer emails shipping to the exact same address |

### Fulfillment

| Page | What it does |
| --- | --- |
| **Voided Shipments** | ShipStation shipments voided in the selected date range |
| **Fulfillment SLA Breaches** | Orders exceeding your time-to-first-fulfillment SLA by shipping method and region |
| **Bundle Check** | Bundled orders missing required companion items (Addon items) |
| **Partial Fulfillment Stalls** | Open orders partially shipped with unfulfilled items stalled for N+ days |
| **On-Hold Stall** | Fulfillment orders sitting on hold - sorted by how long the order has been waiting |
| **Fulfilled Without Tracking** | Fulfilled orders with no tracking number after a configurable grace period |
| **Shipment Aging** | ShipStation awaiting-shipment orders older than a configurable threshold |
| **Shipped Item Mismatch** | ShipStation shipped items that don't match what was ordered in Shopify - catches picking errors, especially missing accessories on bundled products |
| **Fulfilled Items Report** | Itemized quantity totals for orders fulfilled in a date range |

### Carrier Analytics

| Page | What it does |
| --- | --- |
| **Carrier Performance** | Avg delivery time, late rate, and order count grouped by carrier for a date range |
| **Financial Exceptions** | Manual Shopify Payments payout anomaly checks using included transactions and explicit thresholds |
| **Order Contribution Margin** | Shopify-reported net revenue and historical product costs, payment fees and matched ShipStation labels, with explicit missing costs and currency coverage |
| **Shipping Margin Erosion** | Orders where the ShipStation label cost exceeds what the customer was charged for shipping — flags orders shipped at a loss |

### Products & Inventory

| Page | What it does |
| --- | --- |
| **Product Completeness** | Active products missing images, descriptions, or variant SKUs |
| **SKU Duplicates** | Variants sharing the same SKU across your product catalog |
| **Inventory Oversell Risk** | SKUs where ShipStation awaiting qty exceeds available Shopify stock |
| **Inventory Aging** | Zero-stock active variants that still sold recently |
| **Inventory Forecast** | Days until zero stock based on 30-day sell-through rate per SKU |
| **Zombie Products** | Active products with no variants or all tracked variants permanently out of stock |
| **Product Sync & Package Weight** | Compare SKU counterparts, product customs defaults and the declared weight of a selected shipment |
| **Catalog Quality** | Active product publishing, SEO and collection gaps, with optional Shopify/ShipStation customs-default checks and source coverage |

### Gift Cards

| Page | What it does |
| --- | --- |
| **Gift Cards** | Unused or soon-to-expire gift card balances |

### Fraud & Compliance

| Page | What it does |
| --- | --- |
| **Billing ≠ Shipping Country** | Paid orders where billing and shipping countries differ - a documented fraud signal |
| **Discount Abuse** | Discount code clusters at the same shipping address across different emails |
| **Tag Policy Audit** | Required and forbidden Shopify tag combinations from local policy rules |
| **Tax Audit** | Paid orders above a minimum amount with $0 tax charged to a non-exempt customer |
| **Marketing Consent Audit** | Orders from customers without active email marketing consent - a compliance risk if targeted |
| **Fraud Risk Report** | Paid orders scored by combined fraud signals - disposable email, country mismatch, HIGH risk level, and more |
| **Same IP, Different Emails** | Client IP addresses used by two or more distinct customer emails - a fraud ring signal |
| **Chargebacks / Disputes** | Open Shopify Payments disputes needing evidence, sorted by response deadline |

<!-- AUTO-GENERATED:AUDIT-SECTION:END -->

## Search & Lookup

| Page | What it does |
| --- | --- |
| **Spot-check** | Live lookup of 1–50 order numbers in ShipStation and/or Shopify simultaneously. |
| **Metafields** | Browse metafield definitions, search orders by metafield value, or look up all metafields on a specific order. |
| **Tag Search** | Find all Shopify orders with a specific tag - fast, native index, no full scan. |
| **Tag Audit** | Build a complete tag inventory across a date range with frequency and last-seen info. |
| **Customer Lookup** | Full order history for a customer by email, with lifetime spend summary. |
| **Customer LTV** | Top customers by lifetime value and monthly cohort retention for the selected period. |
| **Tracking Feed** | Live tracking details for 1–30 orders with direct links to carrier tracking pages. |
| **Order Compare** | Side-by-side comparison of two Shopify orders with differing fields highlighted. |
| **Order Timeline** | Merged Shopify + ShipStation event timeline for a single order. |
| **Global Search** | Search order number across audit reports, push log, and ignored orders at once. |
| **Packing Slip Preview** | Fetch and render a ShipStation packing slip for any order. Print-optimised. |

## Manage

| Page | What it does |
| --- | --- |
| **Ignored Orders** | View and manage all ignored orders. Single ignore, checkbox bulk-ignore from Run Audit, Refunds Tracker, Email Checker, Address Scanner, and Audit Trends; bulk-unignore and CSV import. Ignoring excludes orders from Run Audit, not from the source reports. Recurrence badges show orders that keep coming back missing. |
| **Push Log** | Full history of every order pushed to ShipStation from the dashboard, filterable by order/Shopify ID. |
| **Run History** | Recent audit and scan executions with status, duration, scanned count, issue count, and errors, filterable by tool/status/error. |
| **Job Queue** | Store-scoped queued/running/completed/failed audit jobs processed by the Laravel queue worker (`php artisan queue:work`), plus generic queue diagnostics (Horizon when Redis-backed). |

## Settings (admin)

| Page | What it does |
| --- | --- |
| **Configuration** | Store credentials (Shopify, ShipStation), integration status. |
| **API Health** | Live Shopify/ShipStation health checks, Shopify API version match, and required Shopify scopes. |
| **Config Check** | Validate [`config/order-types.php`](../config/order-types.php) and [`config/tag-policy.php`](../config/tag-policy.php), plus runtime application/store/cache/queue/mail/notification configuration. |
| **Webhook Health** | Lists registered Shopify webhooks and flags unhealthy ones (wrong URL scheme, stale API version). |
| **Slack Rules** | Configure thresholds for Slack audit and scan notifications, and the `@mention` prefix. |
| **Discord Rules** | Configure thresholds for Discord audit and scan notifications. |
| **Email Rules** | Per-tool email delivery mode (off / immediate / digest), threshold, and recipient, plus a global fallback recipient. |
| **Stores** | Manage multi-store credentials and access. |
| **Users** | Manage operator accounts and roles (viewer / operator / admin). |
| **Action Log** | Operator audit trail for ignore/unignore, ShipStation pushes, print queue changes, queued audits, order notes, store switches, cache flush, banned-IP unbans, and rule changes. |
| **Banned IPs** | View and manually unban IPs locked out after repeated failed logins. |
| **Backups** | Trigger and download database/application backups. |
| **Health** | Framework health checks (database, cache, queue, scheduler heartbeat). |

## Operational workflows

- **Operational Issues** collects store-scoped findings with owners, priorities, due dates and open/in-progress/resolved/ignored states. ShipStation synchronization monitoring adds shipment-specific fulfillment/tracking findings only after explicit activation in the store settings.
- **Order remediation** previews an operator-requested change, requires confirmation and verifies current permissions/state before writing. See [Order remediation](search-lookup.md#order-remediation).
- **Order Contribution Margin** combines Shopify-reported revenue and product costs, payment fees and matched SS label costs. Missing or incompatible costs remain unknown rather than zero; the result is contribution on that cost basis, not accounting net profit.
- **Catalog Quality / customs readiness** optionally checks HS codes, origin countries and weight sources. Conflicting defaults, duplicate SKUs, exclusions and incomplete source coverage are review findings, not presumed readiness.
- **Rate Shopping Audit** distinguishes captured quote decisions from current-rate simulations. A selected service requires confirmation, access/expiry checks and verification of the ShipStation result. It does not reconstruct historical rates from current quotes.
- **Return / RMA Tracker** identifies overdue approvals, processing after receipt and unfinished exchanges using configurable business-day deadlines.
- **Operational Digest** summarizes pending orders, synchronization findings, unresolved issues and fulfillment deadlines; delivery is configured through the existing email rules.

The interface supports English and Bulgarian. Manual tools remain available independently of optional monitoring.

### Product Sync & Package Weight

Run this read-only report from **Audit → Products & Inventory**. Catalog mode compares Shopify variants with active account-wide SS products by exact SKU, including missing counterparts, duplicate SKUs, weight, customs-description candidates, HS codes and origin countries. Shopify catalog scans are bounded to 20 pages of 100 variants; SS defaults use the existing bounded product reader. Incomplete coverage is explicit and does not establish that a SKU is missing. An SS-only SKU may belong to another store sharing that account.

Shipment mode requires an order number. Leave Shipment ID blank to choose from its non-voided outbound label shipments, then inspect one package. The report verifies the SS store and order identity and uses only the chosen shipment's items/quantities. It displays current Shopify weights, current SS product defaults, imported order item weights and reported shipment item weights separately. No missing snapshot weight is filled from a current default. Prepared customs rows belong to the current imported order; V1 does not confirm the historical label declaration.

Enter package tare and its unit explicitly; blank tare remains unknown, while an explicit zero is allowed. Native bundle parents use confirmed current component quantities/weights, without adding the parent weight again. Missing, truncated, nested or ambiguous bundle mapping leaves the estimate unknown. Separate shipped component lines are counted individually. Current data does not establish historical composition or a scale measurement.

Optional DIM input uses the reported dimensions and your carrier-specific divisor in cm³/kg or in³/lb. The displayed billable scenario is the larger of declared and calculated dimensional weight, without presumed carrier rounding or billing rules. Real measured weight and carrier adjustments require another authoritative source; this report does not infer a wrong product weight from a discrepancy. External writes and scheduled monitoring are not added.

### Financial Exceptions

This is a manually requested **Shopify-only anomaly report**, not a bank or accounting reconciliation tool. Choose a Shopify Payments payout or enter its ID, set the pending-age limit in calendar days since issue date, and choose a net tolerance in that payout's currency. An optional adjustment threshold flags absolute adjustment amounts strictly above the threshold; leaving it blank disables that rule. These inputs are saved with the result. No automatic monitoring or external writes are introduced.

The report loads the actual associated balance transactions using `payments_transfer_id` and checks each transaction's payout identity. It displays signed amount, fee, net, linked order/transaction and adjustment-order details. Component totals use Shopify's signed `net` once: fees, refunds, reserve movements and adjustment-order subrows are not subtracted or added again. Payout transfer ledger rows are excluded to avoid double counting. Decimal strings use the already-installed Brick Math library rather than floating-point arithmetic.

Findings cover failed/canceled Shopify statuses, pending age beyond the selected limit, adjustments over the chosen amount and net differences beyond the selected tolerance. Age is not a claim about when the status last changed. A net comparison is available only for finalized deposit payouts with complete supported transaction data in one currency. Withdrawals, missing/invalid fields, test data, unsupported transaction types and mixed currencies do not receive a confirmed comparison. Missing order links on adjustments are not automatically treated as errors.

Payout selection is paginated in parts of 25. Transaction scans are bounded to 20 pages of 100, reject duplicate/nonadvancing cursors and re-read the payout to detect changes during the scan. A changed or truncated source remains incomplete. Observed partial totals are explicitly labelled; no differences are asserted from them. Shopify Payments absence or missing payout permissions is a failed/unavailable check, not zero payouts.

Shopify's PAID status is displayed as Shopify's assertion, without independently verifying bank receipt. Daily sales are never compared with same-day payout totals. Bank imports, bank matching and accounting reconciliation are outside this feature. See the official [Shopify Payments account API](https://shopify.dev/docs/api/admin-graphql/latest/objects/shopifypaymentsaccount) and [balance transaction fields](https://shopify.dev/docs/api/admin-graphql/latest/objects/shopifypaymentsbalancetransaction).
