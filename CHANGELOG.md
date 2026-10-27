# Changelog

All notable changes to this project are documented here. Versions match the git tags.

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
