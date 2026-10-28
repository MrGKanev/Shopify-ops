# Changelog

All notable changes to this project are documented here. Versions match the git tags.

## [3.1.0] - 2026-10-28

### Added

- **Pre-shipment drift check** (print queue): compares the oldest 50 open ShipStation orders with the quantities Shopify still needs shipped, read from fulfillment orders per SKU. Open ShipStation orders sharing a number are summed, so valid partial and split orders are not compared with the whole original order. Reports items ShipStation would still ship that Shopify no longer needs, items Shopify still needs that ShipStation lacks, cancelled orders, and shipping address differences (formatting changes are separated from substantive ones such as a dropped second address line). Re-check flags for weight/package and customs appear when items differ. Orders that cannot be read completely, are ambiguous or lack SKUs are shown as unknown, never as a match.
- **Order consolidation review** (print queue): lists open orders from the same customer, recipient and full destination that could ship together, with per-order allocations, a configurable waiting window, ship-from/service/confirmation matching, and explicit reasons for every excluded order. Orders with paid shipping, customs data, insurance, gift or special handling stay out until marked reviewed; operators can also exclude an order (saved per store). Optional savings use two operator-entered cost assumptions, never guessed package sizes. CSV export included. Nothing is merged, held or delayed.
- **Import freshness** (issue triage): ages the expected-but-absent orders found by Run Audit against a per-store grace window (default 6 hours, 1-720), shows the latest and last successful audit, and can read the ShipStation refresh status on request, displayed as reported and never used to label a connection failed. A single alert per store is raised through the existing operational alerts while orders stay absent beyond the window; quiet stores never alert.
- **Billing cost benchmarks** on the Shipping Billing Audit: observed cost per SKU set and service in each currency with label count, sample size and coverage, median, mean, min and max, a small-sample marker and the source shipment IDs. Only single-charge, single-identity labels are sampled, and a mixed basket's postage is never attributed to one SKU.

### Changed

- `OrderChannelComparator` gained `addressDifferences()`, reused by the drift check.
- The admin appearance test reads the expected version from `package.json` instead of a hard-coded number.

### Fixed

- Import-freshness ages are never negative when a first-seen time is ahead of the clock.

## [3.0.0] - 2026-10-27

The legacy PHP system was replaced by a Laravel application (cutover on 2026-09-15). Everything below is new since 2.2.0.

### Added

- **Pick lists** for ShipStation batches in the print queue: saved column, sort and size templates (including an extra-large preset), SKU and variant aggregation, per-order allocations, natural bin sorting, printable output and CSV. Saved snapshots keep a pick list as generated (no customer data, at most 50 per store) and can be reprinted, exported or deleted without asking ShipStation again.
- **Customer context** during fulfillment: verified first/repeat status, prior order count, VIP and milestone tiers, prior purchases of the order's SKUs, saved per-store filters, matching-rule explanations, and an optional line on the packing slip and pick list. Incomplete history shows "unknown", never "first".
- **Operational fields**: per-store aliases for Shopify order metafields and ShipStation customField1-3, required flags, a worklist with missing/unreadable states, saved filters, findings, line-item CSV export, and opt-in columns in the no-tracking, on-hold and shipped-unfulfilled CSVs.
- **Carrier claims** register: unique provider and reference per store, manually verified dates, evidence links and uploaded documents (PDF/JPG/PNG/WebP), communication notes, follow-up reminders, separate goods and postage amounts (requested, approved, received), links to billing credits and existing issues, deadline policies, and CSV export. The weekly `claims:prune-orphan-files` command (with `--dry-run`) removes orphaned evidence files.
- Store reporting workflows, financial and product sync audit reports, delivery exception monitoring and retries, order remediation and webhook tooling, opt-in ShipStation V1 synchronization monitoring, and Shopify/ShipStation API health checks.
- Queued report runs with persisted results, shared API throttling, and bulk ignore in report views.
- Fortify two-factor authentication, password reset, an expanded command palette, and configurable branding with dashboard insights.
- Queued admin backup restore, an installer, operational health and issue triage, and consolidated admin diagnostics and notifications.
- Date-range filter for run logs and composite `push_logs` indexes; cached ShipStation customs reference data and Shopify metafield definitions.
- Complete Bulgarian UI translation, including validation attributes, plural flash messages and number/date formatting.

### Changed

- Reports share one queued `ReportController` base and one `ScanResult` DTO; duplicated request, authorization and store-resolution code was consolidated.
- PHPStan raised to level 8; tests can run in parallel.
- Dependencies updated, including PHPUnit 13 and patched Vite audit advisories.
- Documentation restructured for the Laravel app.

### Fixed

- Carrier claim evidence files are now removed when a claim or store is deleted.
- Pick list CSV export no longer relies on an untyped default column that could return an array.
- Two audit false-negative/false-positive bugs.
- The dashboard 7-day audit chart.

### Security

- Application security hardening, `dev-login` restricted to loopback requests, and login throttling.

## [2.2.0] - 2026-09-04

Google login, security hardening, SQLite state, and static analysis.

## [2.1.0] - 2026-08-25

Audit table in `docs/tools.md` is generated from `ToolRegistry`.

## [1.9.0] - 2026-07-23

## [1.8.0] - 2026-06-27

Login page redesign; dashboard 7-day audit trend chart, cache status panel with flush, and push statistics.

## [1.5.0] - 2026-06-09

Date-range partial and search input.

## [1.4.0] - 2026-06-03

Cache and page loader update.
