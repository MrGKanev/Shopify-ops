# Независима проверка на Laravel/legacy parity

Този документ е единственото място, което твърди parity между legacy и
Laravel системата. Ред получава verdict, различен от `Not yet audited`,
само след линкнат differential тест, който реално се изпълнява в CI
(`.github/workflows/ci.yml`, `vendor/bin/phpunit -c phpunit.parity.xml`).

Дизайн: [`docs/superpowers/specs/2026-09-13-parity-verification-design.md`](superpowers/specs/2026-09-13-parity-verification-design.md).

Verdict е едно от: `Verified match` / `Verified mismatch (fixed)` /
`Verified mismatch (open)` / `Not yet audited`.

## Прогрес

Знаменателят е 72-та инструмента от `ToolRegistry` (легендата в
[laravel-rewrite.md](laravel-rewrite.md)), плюс отделно проследени
cross-cutting mutating/platform workflows, които не са в 72-те (напр. Push
to ShipStation).

| Статус | Брой | Кои |
|---|---:|---|
| Verified match | 1 | Push to ShipStation (payload construction only — не е един от 72-та) |
| Verified mismatch (fixed) | 1 | `dupes` (pair-matching algorithm only) |
| Verified mismatch (open) | 1 | Run Audit inline duplicates panel — виж реда по-долу |
| **Not yet audited** | **71 от 72** | Всички други инструменти от `ToolRegistry` |

Последно обновено: 2026-09-13, след pilot-а плюс инцидентната находка за
`Comparator::findDuplicates()` по-долу. Скалирането към остатъка следва
risk order-а от [дизайн документа](superpowers/specs/2026-09-13-parity-verification-design.md#Скалиране-към-всичките-72-инструмента):
mutating/notification-triggering инструменти първи, после read-only reports.

| Tool | Legacy reference | Laravel reference | Differential test | Verdict | Notes |
|---|---|---|---|---|---|
| `dupes` (Duplicate Detector) | `src/Shopify/GraphQL/DuplicateOrderInsights.php::findDuplicateOrders()` | `laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php::analyze()` | `tests/Parity/DuplicateDetectorParityTest.php` | Verified mismatch (fixed) — pair-matching algorithm only | Laravel used an 86400s (24-hour) window instead of the real tool's 600s (10-minute) window — over-flagged pairs 10 minutes to 24 hours apart as duplicates. Fixed 2026-09-13; `laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php` updated to match. Scope: only the email+amount+time-window matching logic was verified — the surrounding GraphQL query, controller, and view are not yet audited. |
| Push to ShipStation | `src/ShipStation.php::buildPayload()` | `laravel/app/Integrations/ShipStation/ShipStationClient.php::buildOrderPayload()` | `tests/Parity/PushToShipStationParityTest.php` | Verified match — payload construction only | Field-for-field identical payload construction confirmed against a full order fixture (addresses, line items, shipping, tax). Scope: only the payload-building function was verified — legacy `Actions::performPush()` (order ID lookup via `getOrder()`) and Laravel `PushOrderToShipStation::handle()` (order number lookup via `findByOrderNumber()`) have structurally different entry points that are not yet audited. |
| Run Audit inline duplicates panel | `src/Comparator.php::findDuplicates()` (24h clustering, rendered by `views/run.php:87-103`) | — none — | N/A — nothing exists to differential-test against | Verified mismatch (open) | Confirmed by independent code reading during the pilot's final review (not a differential test, since one side doesn't exist): `laravel-rewrite.md` previously implied this was ported to `DuplicateOrderAnalyzer`, but that class is actually the port of the unrelated `dupes` tool. The Run Audit page's inline "N potential duplicates detected" panel (24h/rounded-amount clustering) has **no Laravel port at all** — `run-audit.blade.php` has no equivalent section. This is a missing-feature gap, not a logic bug; needs a product decision (build it, or accept as a deliberate deviation) before it can move to `Verified match`. See `docs/laravel-todo.md`. |
