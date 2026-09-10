# Laravel rewrite — legacy test audit

Последно обновяване: **2026-09-10** след Security/Ids/Risk Scorer/Reporter/Shopify Client method-level audit-а (плюс закрита gap-проверка на Dispute Lookup normalization).

Този документ е отделният checklist за тестова parity. Feature статусът се следи
в [Laravel rewrite плана](laravel-rewrite.md), а тук се затваря всеки legacy test
файл. Един файл се отбелязва като готов само след method-level сверка: всеки стар
contract има Laravel тест, по-силен еквивалент или записана причина за отпадане.

## Състояние

| Статус | Файлове | Дял от 115 |
|---|---:|---:|
| Готови | 76 | 66.1% |
| Частично покрити | 10 | 8.7% |
| Непочнати | 29 | 25.2% |
| **Оставащи за одит** | **39** | **33.9%** |

Legacy baseline: **115 файла · 1,528 теста · 3,659 assertions**. Laravel
baseline след последния slice: **588 теста · 2,675 assertions**. Броят assertions
е ориентир; критерият е поведенческо покритие.

За всеки checkbox проверяваме business decisions, boundary интеграцията,
authorization и store isolation, validation, escaping, pagination/truncation,
malformed payloads и atomic failure. Не копираме тест, който проверява изцяло
премахнат legacy implementation detail; записваме защо Laravel contract-ът го
заменя.

## Частично покрити — method-level одит остава

| Готово | Legacy файл | Тестове | Какво проверява | Какво остава |
|---|---|---:|---|---|
| [x] | `AllViewsSmokeTest.php` | 1 | Replaced by automatic traversal of every parameterless GET screen backed by an application controller, with authenticated admin/store context and safe webhook boundary |
| [x] | `AuthPermissionSnapshotTest.php` | 2 | Replaced by automatic completeness checks for every report/admin route plus a runtime viewer-denial assertion; this also closed missing POST/export report gates |
| [ ] | `AuthTest.php` | 40 | Пароли, lockout/IP ban, users, CSRF и роли | Lockout/banned-IP и пълната permission матрица |
| [ ] | `GraphQL/EventNormalizerTest.php` | 28 | Нормализация на всички Shopify order event типове | Поле-по-поле сверка на останалите event variants |
| [ ] | `GraphQL/OrderComponentNormalizerTest.php` | 27 | Address, item, shipping, fulfillment, refund и discount нормализация | Shipping/refund/discount полета и edge cases |
| [x] | `FraudComplianceChecksTest.php` | 22 | Country mismatch, high value/no phone и email checker rules са покрити с analyzer unit и HTTP feature тестове; non-ISO country names умишлено се третират като липсващи вместо да създават false positives |
| [x] | `GraphQL/OrderEventLookupTest.php` | 3 | Shopify client integration tests покриват normalized event lookup, cursor pagination/newest-first order и missing order; добавени са malformed shape, cursor и invalid-ID guards |
| [ ] | `GraphQL/OrderNormalizerTest.php` | 39 | Всички основни и optional order fields | Tax, refunds, discounts, attributes, journey/source и support fields |
| [x] | `HttpAuthEndpointTest.php` | 1 | Laravel HTTP feature tests cover login/logout, Google redirect/callback failures, session regeneration, throttling, CSP and authenticated route boundaries |
| [ ] | `OrderInsightPageLoaderTest.php` | 12 | Compare, timeline и допълнителни order insights | Непренесените insight branches и failure states |
| [ ] | `OrderTimelineTest.php` | 26 | Timeline events, ordering, labels и risk signals | Explicit mapping на всички 26 метода |
| [ ] | `OrderPolicyPageLoaderTest.php` | 22 | Policy-report inputs, wiring, configuration и error states | Discount Abuse, Same IP, Tag Policy, Duplicate Shipping Addresses, Note Flags и Order Edit paths са покрити; останалите policy reports чакат method-level сверка |
| [ ] | `ProductInventoryPageLoaderTest.php` | 32 | Wiring за catalogue/inventory report страниците | Оставащите catalogue workflows и финална method-level сверка |
| [ ] | `SecurityTest.php` | 5 | CSP headers (`ContentSecurityPolicyTest.php`) and the `oauth` rate limiter (10/min by IP, now directly asserted) are covered | Trusted-proxy CIDR trust and HSTS are deferred to the real production proxy/TLS setup (tracked separately in the rewrite plan); session absolute-timeout has no Laravel equivalent beyond the native idle-based `session.lifetime` |
| [ ] | `SlackNotifierTest.php` | 19 | Slack payloads, mentions, delivery и safe failure | Queue-ready webhook channel, admin-only delivery diagnostic, trusted endpoint validation и credential-free test payload са готови; audit/scan payloads, mentions и retry mapping остават |
| [ ] | `ShipStationClientTest.php` | 23 | Auth, lookup, retries, create, active/awaiting/shipment fetch и cache | Create order, active/voided/date fetch, cache/checkpoint semantics |
| [ ] | `ShopifyClientTest.php` | 58 | All 57 read methods are mapped: every report-specific fetcher is covered under its own already-closed audit row; generic infra (order/batch lookup, events, webhooks, metafields) is directly tested; the "GraphQL never retries" appearance is a deliberate, tested decision (`graphql()` also carries mutations, so retry would risk double-executing a write — only the idempotent REST `get()` retries); cache-separation matches the established `OrderDirectLookupTest`/`OrderArchiveTest` fresh-reads precedent | `updateOrderNote` mutation has no Laravel equivalent yet — tied to the not-started push-note action in `ActionsTest.php` |
| [x] | `StoresTest.php` | 7 | File-backed stores are replaced by DB stores, user pivots and active-store middleware; first-store fallback, switching, inaccessible-store rejection and session persistence are covered |
| [x] | `ViewSmokeTest.php` | 6 | Fraud Risk, Same IP and Disputes empty/populated rendering is covered by the automatic GET smoke test plus their feature success, escaping and safe-failure tests |

## Непочнати — application и infrastructure

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `ActionsTest.php` | 30 | POST action parsing, user/date validation, connection checks, push preview/order note → Form Requests, controllers и services |
| [x] | `AtomicFileTest.php` | 8 | Replaced: operational state uses database writes/transactions and validated JSON casts; Laravel storage owns atomic filesystem writes where files remain |
| [x] | `AuditSnapshotTest.php` | 9 | Save/load/history/overwrite на audit snapshots → store-scoped DB snapshots and Saved Reports views |
| [x] | `AuditTest.php` | 3 | Success/error execution logging → persisted Run Audit summaries and safe failure records |
| [x] | `AutoloadCoverageTest.php` | 1 | Automatic PSR-4 check loads every PHP symbol under `app/` through Composer |
| [ ] | `CacheTest.php` | 47 | TTL, locking, corruption, pruning и namespaces → Laravel cache/lock policy и integration tests |
| [x] | `ConfigValidatorTest.php` | 35 | Replaced by runtime Laravel application/security, DB store credentials, order-type/tag-policy contracts and admin-only rendering |
| [x] | `DateRangeTest.php` | 10 | Отделните GET/POST routes премахват input precedence двусмислието; Form Requests покриват строг ISO формат и ред на датите, а Carbon покрива calendar arithmetic и седемдневното ShipStation разширение |
| [x] | `DocsGeneratorTest.php` | 1 | Feature tracker count is executable and must remain exactly 72; routes are the Laravel source of truth instead of generated legacy tool docs |
| [x] | `IgnoreListTest.php` | 16 | Ignore CRUD, normalization, expiry и persistence → ignore-list model/repository |
| [x] | `JsonFileLockTest.php` | 6 | Replaced by database transactions/unique constraints and native Laravel cache locks; no shared JSON state remains in Laravel workflows |
| [ ] | `LoggerTest.php` | 7 | Structured logging, redaction и rotation → Laravel logging config/tests |
| [ ] | `MetricsEndpointTest.php` | 4 | Metrics auth/content/counters → operational metrics endpoint |
| [x] | `PrintQueueTest.php` | 7 | Queue persistence, ordering и removal → DB-backed store-scoped packing-slip queue |
| [x] | `PushLogTest.php` | 3 | Push history append/order/limit → store-scoped DB push log |
| [x] | `ReportRegistryTest.php` | 7 | Named routes are the report registry; executable checks enforce unique names and a submit route for every report screen |
| [x] | `RunLogTest.php` | 3 | Run history append/order/limit → DB run records |
| [ ] | `ScanRunnerTest.php` | 18 | Scan orchestration, notifications, snapshots и failures → queued report orchestration |
| [ ] | `ShopifyFlowHealthTest.php` | 7 | Per-tool run/error health aggregation → operational dashboard |
| [ ] | `SidebarSettingsTest.php` | 4 | Sidebar visibility defaults/persistence → user UI preferences |
| [ ] | `ToolRegistryTest.php` | 8 | Page titles/groups/full registry → route/navigation registry parity |
| [ ] | `ViewHelpersTest.php` | 83 | Formatting, badges, tables, forms, escaping и order links → Blade components/helpers |
| [ ] | `WorkerTest.php` | 11 | Store resolution, credentials и audit worker orchestration → tenant-aware Laravel jobs |

## Непочнати — authentication и notifications

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `DiscordNotifierTest.php` | 17 | Configuration, payloads, escaping, delivery и safe failure → Discord notification channel |
| [ ] | `EmailDigestTest.php` | 10 | Daily selection, thresholds, grouping и latest run → scheduled digest job |
| [ ] | `EmailNotifierTest.php` | 30 | SMTP config, audit/scan/digest messages, escaping и attachments → Laravel mailables |
| [ ] | `EmailRulesTest.php` | 25 | Per-tool modes, thresholds, recipients и persistence → notification preference model |
| [ ] | `ManageSettingsPageLoaderTest.php` | 14 | Settings load/save, validation и authorization → admin settings workflow |

## Непочнати — order, fulfillment и logistics workflows

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [ ] | `ComparatorTest.php` | 73 | Shopify↔ShipStation matching, exclusions, duplicates, bundles, shipped items, margin и hold behavior |
| [x] | `CustomerLTVPageLoaderTest.php` | 28 | Revenue/customer cohorts, cancellation, identity normalization, retention and range wiring |
| [ ] | `FulfillmentIssuePageLoaderTest.php` | 36 | Loader contracts за fulfillment exceptions, filters, dates, credentials и failures |
| [ ] | `OrderAnomalyPageLoaderTest.php` | 20 | Fraud/anomaly page dispatch, ranges, credentials, results и failures |
| [ ] | `PageLoaderTest.php` | 18 | Главен audit loader, compare results, ignore rules и notification behavior |
| [ ] | `SimpleScanPageLoaderTest.php` | 19 | Shared tag/tax/returns/email report loader, validation and notifications | Email wiring/credentials са покрити; returns и notification branches остават |

## Непочнати — Shopify GraphQL contracts

| Готово | Legacy файл | Тестове | Какво проверява / Laravel цел |
|---|---|---:|---|
| [x] | `GraphQL/AdminLookupsTest.php` | 3 | Legacy facade е заменен с директен Shopify gateway contract; order, metafield и customer lookups са покрити на client и HTTP controller границите |
| [x] | `GraphQL/CustomDataLookupsTest.php` | 6 | Metafield search, counts, samples, dedupe и query escaping |
| [x] | `GraphQL/CustomerOrderInsightsTest.php` | 6 | Customer spend, identity selection, email normalization and defaults |
| [x] | `GraphQL/DuplicateOrderInsightsTest.php` | 7 | Duplicate window boundary, amount/email matching and scanned count |
| [x] | `GraphQL/MetafieldNormalizerTest.php` | 6 | Types, JSON, references and malformed metafield values |
| [ ] | `GraphQL/OrderAuditsTest.php` | 4 | Audit facade delegation към query/event fetchers |
| [ ] | `GraphQL/OrderEventAuditsTest.php` | 8 | Edited/address-change event selection, batching and ordering |
| [ ] | `GraphQL/OrderFetcherTest.php` | 6 | Generic pagination, normalization, cache and malformed responses |
| [ ] | `GraphQL/OrderHoldLookupTest.php` | 10 | Fulfillment hold detection, batching, pagination, IDs and cache |
| [x] | `GraphQL/OrderInsightsTest.php` | 2 | Legacy facade е премахнат; tag search и duplicate-order insights се изпълняват директно през Shopify gateway и са покрити с integration, analyzer и controller тестове |
| [ ] | `GraphQL/OrderLookupTest.php` | 4 | Lookup facade delegation for direct order, hold and events |
| [ ] | `GraphQL/OrderQueryAuditsTest.php` | 10 | Exact filters/fields за address, refund, fulfillment, fraud and cancellation fetches |
| [ ] | `GraphQL/ProductNormalizerTest.php` | 9 | Product/variant/image normalization and missing-field defaults |
| [ ] | `GraphQL/QueryStringsTest.php` | 2 | Exact partial-fulfillment GraphQL filters |

## Напълно сверени

- [x] `GraphQL/OrderDirectLookupTest.php` — `#`-stripping, normalization, empty-result and batch dedup/keying-with-misses are covered by the Shopify gateway test; legacy `getOrder`-by-GID is consolidated into the same name-based `findByOrderNumber` query (Laravel always resolves orders by name), and the 60s cache pass-through is intentionally dropped for fresh reads.
- [x] `JobQueueTest.php` — native `jobs`/`failed_jobs` visibility and retry/forget are covered by the admin queue controller test; `RunAuditJob::handle()` store resolution and delegation to `RunAudit` (success and missing-store failure) are covered directly; the queue worker's own failure/retry mechanics are Laravel framework behavior, not app logic.
- [x] `UserActionLogTest.php` — newest-first history and store-change/credential-rotation logging are covered by the DB-backed admin Action Log (Spatie Activitylog); scheduled retention (`activitylog:clean`) is now directly asserted; the legacy JSON→SQLite storage-driver import is a dual-storage migration artifact with no Laravel equivalent (the Action Log is DB-native from day one, same reasoning as `AtomicFileTest.php`/`JsonFileLockTest.php`).
- [x] `AuthViewsTest.php` — login mode toggling, incomplete-config error, and escaping are covered by the login feature tests; the dedicated access-denied page is replaced by an inline session-flashed error on the login form, and each denial reason (unknown user, disallowed domain, google-id conflict) now asserts its exact displayed message instead of a generic "has errors" check; per-deployment branding (logo/app name/background image) and the localhost dev-login bypass are intentionally dropped in the rewrite (single fixed brand, no auth-bypass route).
- [x] `ReporterTest.php` — this legacy class only has two public methods, both CLI-era: `saveReports()` (local CSV/TXT files) and `printSummary()` (terminal echo). Neither has a Laravel equivalent — the multi-tenant web app persists the same `run_audit` report to `audit_snapshots`/`run_logs` DB tables and renders it in the Admin UI (`RunAuditControllerTest.php`) instead of writing local files or echoing to a terminal; the "spot-check" section maps to the separate, already-closed `SearchLookupPageLoaderTest.php` (`OrderBatchLookupController`). Added the one missing case: the zero-missing "OK" success state and its `run_logs` status. There is no JSON output anywhere in the legacy class or its tests — that part of the old audit note was inaccurate.
- [x] `RiskScorerTest.php` — all 8 default signal weights match legacy exactly, verified via `OrderRiskScorerTest.php`'s per-signal data provider (each signal's exact weight), the four threshold-boundary pins (20/low, 25/medium, 50/medium, 55/high) and multi-signal accumulation in legacy evaluation order — a superset of the legacy 33 tests. The `data/risk_weights.json` custom-weight override is intentionally not ported: it was never shipped with an actual `.example` file even in the legacy app, has no dedicated management UI, and is only mentioned in a help blurb — a YAGNI candidate, not a test gap.
- [x] `GraphQL/IdsTest.php` — there is no shared reusable ID utility class in Laravel; the legacy `orderGid`/`legacyId` contract is duplicated as small private helpers on `ShopifyAdminClient`/`ShopifyOrderNormalizer` scoped to each caller. Numeric-ID-to-GID conversion, GID-passthrough (the actual code path `LoadOrderTimeline` uses), invalid-ID rejection, and GID-fallback legacy-ID extraction (line items, transactions with no `legacyResourceId`) are all covered through the real order/event normalization tests; a dedicated GID-passthrough assertion was added to `getOrderEvents`. Query-string-in-GID and non-numeric-`legacyResourceId` are theoretical shapes Shopify never actually returns and are not worth separate coverage.
- [x] `GraphQL/DisputeLookupTest.php` — open-status filter, normalization (including reason, network reason code, amount, currency and order name), cursor pagination and disputes without an order.
- [x] `GraphQL/OrderArchiveTest.php` — inclusive all-status date range, normalized multi-page results and cursor forwarding are covered by the customer LTV gateway; legacy cache pass-through is intentionally replaced by fresh report reads.
- [x] `SlackRulesTest.php` — audit/scan thresholds, defaults, mention normalization, DB persistence and shared delivery wiring.
- [x] `SearchLookupPageLoaderTest.php` — 19/19 global search and lookup loader contracts.
- [x] `PackingSlipPageLoaderTest.php` — 6/6 legacy paths.
- [x] `TrackingFeedTest.php` — 7/7 builder contracts.
- [x] `GraphQL/OrderTagInsightsTest.php` — 8/8 tag search/statistics contracts.
- [x] `ProductCatalogueChecksTest.php` — 16/16 catalogue decisions.
- [x] `CatalogQualityTest.php` — 6/6 quality decisions.
- [x] `GiftCardPageLoaderTest.php` — 4/4 visible workflow/failure paths.
- [x] `GiftCardsTest.php` — 8/8 gift-card decisions.
- [x] `InventoryForecastTest.php` — 9/9 forecast decisions.
- [x] `ZombieProductsTest.php` — 7/7 zombie-product decisions.
- [x] `TaxAuditTest.php` — 6/6 zero-tax, exemption, minimum and sorting decisions.
- [x] `ConsentAuditTest.php` — 4/4 email/SMS consent, unknown and sorting decisions.
- [x] `FraudRiskReportTest.php` — 4/4 filtering, signal breakdown, Shopify risk and sorting decisions.
- [x] `AddressCheckTest.php` — 20/20 required fields, postal formats, province, PO Box, carrier and malformed-value decisions.
- [x] `AddressScannerPageTest.php` — 4/4 severity sorting, PO Box filter and clean-address decisions.
- [x] `SameIpTest.php` — 5/5 exact-IP grouping, distinct-email deduplication, exclusions and sorting decisions.
- [x] `OrderPolicyChecksTest.php` — 16/16 Discount Abuse и Tag Policy configuration, required/forbidden semantics и tag normalization decisions.
- [x] `DisputesPageLoaderTest.php` — 7/7 deadline computation, urgency sorting, initial/configuration and Shopify success paths.
- [x] `RepeatRefundsTest.php` — 8/8 threshold, successful-transaction totals, identity grouping and sorting decisions.
- [x] `RefundsTrackerTest.php` — 10/10 missing/active/complete ShipStation status, refund subtotal/fallback, exact number matching and risk sorting decisions.
- [x] `ReturnRmaTrackerTest.php` — 7/7 per-refund rows, notes, item details, SKU aggregation/exclusions and newest-first sorting decisions.
- [x] `ReturnedItemsReportTest.php` — 10/10 refund-date filtering, old-order inclusion, product quantity aggregation, CSV columns/formula safety and escaped output decisions.
- [x] `ApiHealthTest.php` — 8/8 Shopify/ShipStation configuration, live request, scope, returned-version and safe failure decisions; persisted flow health остава отделен capability.
- [x] `AddressChangesTest.php` — 4/4 placement-to-change delay, negative/missing timestamp clamping and current-address output decisions.
- [x] `GoogleAuthFlowTest.php` — OAuth redirect/callback/state handling is delegated to Socialite; cancellation, provider failure, domain policy, existing-user linking, session rotation and throttled routes are covered.
- [x] `GoogleAuthTest.php` — the custom OIDC/PKCE HTTP client is replaced by Socialite; configuration, verified `hd` claims, domain parsing, identity binding and safe failures are covered without persisting provider tokens.
- [x] `BundleCheckPageTest.php` — 9/9 bundle companion and exclusion decisions.
- [x] `CarrierPerfTest.php` — 8/8 carrier grouping, delivery and late-boundary decisions.
- [x] `FulfillmentLogisticsChecksTest.php` — 17/17 SLA and shipment-aging decisions.
- [x] `ItemizedFulfillmentReportTest.php` — 21/21 fulfillment item/date/grouping decisions.
- [x] `OnHoldStallTest.php` — 5/5 wait, hold detail and sorting decisions.
- [x] `PartialFulfillStallsTest.php` — 6/6 partial stall and remaining-item decisions.
- [x] `PostShipAddrChangeTest.php` — 5/5 post-ship timing and sorting decisions.
- [x] `VoidedShipmentsTest.php` — 5/5 voided shipment and nullable-address decisions.
- [x] `GraphQL/CatalogAndFulfillmentTest.php` — 5/5 inclusive on-hold date-filter decisions.
- [x] `OrphanDetectorTest.php` — 7/7 plain, compound, addon-prefixed, empty and sorted orphan decisions.
- [x] `ActiveSsConflictsTest.php` — 6/6 dedupe, refund/cancellation, active matching and sorting decisions.
- [x] `SsShippedUnfulfilledTest.php` — 6/6 shipped counting, match/exclusion, partial state and sorting decisions.

## Как се обновява

1. Преди нов workflow се отварят всички свързани редове и legacy test methods.
2. В PR/slice описанието се записва mapping: legacy method → Laravel test.
3. При частично покрит файл редът остава unchecked и „Какво остава“ се свива.
4. При пълна сверка редът се мести в „Напълно сверени“, а числата горе и в
   `laravel-rewrite.md` се обновяват.
5. След всеки slice се пуска целият Laravel suite; baseline-ът се актуализира
   само при успешно пълно изпълнение.
