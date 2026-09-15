# Parity Verification Pilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a differential-test harness that runs identical fixture data through legacy PHP classes and their Laravel equivalents in one PHPUnit process, then prove (or disprove) parity for two pilot tools — Duplicate Detector and Push to ShipStation — recording the outcome in a new, independently-maintained report.

**Architecture:** A standalone `phpunit.parity.xml` config with its own bootstrap loads both `vendor/autoload.php` (legacy) and `laravel/vendor/autoload.php` (Laravel) into one process — no Laravel HTTP kernel/container boot, since the domain classes under test are plain PHP with no facade dependencies. Each differential test builds one fixture, feeds it through the legacy code path and the Laravel code path, and asserts the outputs agree. `docs/parity-verification.md` records one row per verified tool, linked to the test that proves it.

**Tech Stack:** PHPUnit 13 (both the root and `laravel/` copies, used independently), GuzzleHttp `MockHandler` (already a dependency of the root `guzzlehttp/guzzle` package) to fake the Shopify GraphQL transport for the legacy code path — no real network calls anywhere.

**Spec:** [`docs/superpowers/specs/2026-09-13-parity-verification-design.md`](../specs/2026-09-13-parity-verification-design.md)

## Global Constraints

- No real network requests in any differential test — fake the HTTP/GraphQL transport boundary on the legacy side, never call live Shopify/ShipStation/SMTP/Slack/Discord/Google.
- Legacy application files (`src/`, `views/`, `index.php`, existing `tests/`, existing `phpunit.xml`, `tests/bootstrap.php`) are read-only in this plan — only new files are added under `tests/Parity/` and a new `phpunit.parity.xml`. Nothing legacy gets modified or deleted.
- `docs/parity-verification.md` is the only place that asserts `Verified match` / `Verified mismatch (fixed)` / `Verified mismatch (open)` for a tool, and only after a linked test that actually runs.
- When a differential test disproves an existing "Done" claim in `docs/laravel-platform-audit.md` or `docs/laravel-rewrite.md`, correct that row in place (append what was wrong/fixed and link to the proof) — never restructure those documents.
- Laravel production code (`laravel/app/**`) may be modified when a differential test proves a real behavior gap — that is the point of this work. Legacy code is never modified to make a test pass.
- No legacy file deletion in this plan. Full legacy removal is out of scope until every tool is verified (tracked separately, per the spec's "Финален cutover" section).

---

## File Structure

- `phpunit.parity.xml` — new root-level PHPUnit config, separate from `phpunit.xml`, so the dual-autoload bootstrap never touches the existing 115-file legacy suite.
- `tests/Parity/bootstrap.php` — requires both autoloaders.
- `tests/Parity/DualAutoloadSmokeTest.php` — proves the harness works before any real differential test is built on top of it.
- `docs/parity-verification.md` — the new independent report; starts as a skeleton table, gains one row per task.
- `tests/Parity/DuplicateDetectorParityTest.php` — differential test for the `dupes` tool.
- `laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php` — fixed if the differential test proves a gap.
- `laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php` — updated to match the corrected behavior.
- `tests/Parity/PushToShipStationParityTest.php` — differential test for Push to ShipStation.
- `docs/laravel-rewrite.md`, `docs/laravel-platform-audit.md` — corrected in place only if/where a test disproves their claims.

---

### Task 1: Dual-autoload differential-test harness

**Files:**
- Create: `tests/Parity/bootstrap.php`
- Create: `phpunit.parity.xml`
- Create: `tests/Parity/DualAutoloadSmokeTest.php`
- Create: `docs/parity-verification.md`

**Interfaces:**
- Produces: a runnable `vendor/bin/phpunit -c phpunit.parity.xml` command. Later tasks add test classes under `tests/Parity/` (autodiscovered via the `<directory>tests/Parity</directory>` testsuite entry) and rows to `docs/parity-verification.md`.

- [ ] **Step 1: Write the smoke test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DualAutoloadSmokeTest extends TestCase
{
    public function test_legacy_and_laravel_classes_load_in_the_same_process(): void
    {
        $this->assertSame([], \Comparator::findDuplicates([]));
        $this->assertSame([], (new \App\Domain\Reports\DuplicateOrderAnalyzer())->analyze([]));
    }
}
```

Save as `tests/Parity/DualAutoloadSmokeTest.php`.

- [ ] **Step 2: Run it to confirm there's no config yet**

Run: `vendor/bin/phpunit -c phpunit.parity.xml`
Expected: FAIL — `Cannot open file "phpunit.parity.xml"` (or similar "config not found" error).

- [ ] **Step 3: Write the bootstrap**

```php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/laravel/vendor/autoload.php';
```

Save as `tests/Parity/bootstrap.php`.

- [ ] **Step 4: Write the PHPUnit config**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/Parity/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnDeprecation="true"
         failOnPhpunitDeprecation="true">
    <testsuites>
        <testsuite name="Parity">
            <directory>tests/Parity</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Save as `phpunit.parity.xml` (repo root, alongside the existing `phpunit.xml`).

- [ ] **Step 5: Run it again to confirm the harness works**

Run: `vendor/bin/phpunit -c phpunit.parity.xml`
Expected: PASS — 1 test, 2 assertions.

- [ ] **Step 6: Create the report skeleton**

```markdown
# Независима проверка на Laravel/legacy parity

Този документ е единственото място, което твърди parity между legacy и
Laravel системата. Ред получава verdict, различен от `Not yet audited`,
само след линкнат differential тест, който реално се изпълнява
(`vendor/bin/phpunit -c phpunit.parity.xml`).

Дизайн: [`docs/superpowers/specs/2026-09-13-parity-verification-design.md`](superpowers/specs/2026-09-13-parity-verification-design.md).

Verdict е едно от: `Verified match` / `Verified mismatch (fixed)` /
`Verified mismatch (open)` / `Not yet audited`.

| Tool | Legacy reference | Laravel reference | Differential test | Verdict | Notes |
|---|---|---|---|---|---|
```

Save as `docs/parity-verification.md`.

- [ ] **Step 7: Commit**

```bash
git add tests/Parity/bootstrap.php phpunit.parity.xml tests/Parity/DualAutoloadSmokeTest.php docs/parity-verification.md
git commit -m "test(parity): add dual-autoload differential-test harness"
```

---

### Task 2: Duplicate Detector differential test (finds and fixes a real regression)

**Files:**
- Create: `tests/Parity/DuplicateDetectorParityTest.php`
- Modify: `laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php`
- Modify: `laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php`
- Modify: `docs/parity-verification.md`
- Modify: `docs/laravel-rewrite.md`

**Interfaces:**
- Consumes: legacy `\Shopify\GraphQL\DuplicateOrderInsights::findDuplicateOrders(string $start, string $end): array{pairs: list<array{0: array, 1: array}>, scanned: int, truncated: bool}` (constructed with `new \Shopify\GraphQL\Client(string $baseUrl, string $token, ?HandlerStack $stack)`), and Laravel `\App\Domain\Reports\DuplicateOrderAnalyzer::analyze(array $orders): list<array{first: array, second: array, gap_seconds: int}>`.
- Produces: nothing consumed by later tasks — this task is self-contained.

- [ ] **Step 1: Write the failing differential test**

```php
<?php

declare(strict_types=1);

use App\Domain\Reports\DuplicateOrderAnalyzer;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\DuplicateOrderInsights;

final class DuplicateDetectorParityTest extends TestCase
{
    public function test_matched_pairs_agree_with_legacy_within_the_real_ten_minute_window(): void
    {
        $orders = [
            ['name' => '#1001', 'email' => 'jane@example.com', 'amount' => '50.00', 'created_at' => '2026-09-01T10:00:00Z'],
            ['name' => '#1002', 'email' => 'jane@example.com', 'amount' => '50.00', 'created_at' => '2026-09-01T10:10:00Z'],
            ['name' => '#1003', 'email' => 'jane@example.com', 'amount' => '50.00', 'created_at' => '2026-09-01T11:00:00Z'],
        ];

        $this->assertSame($this->legacyPairs($orders), $this->laravelPairs($orders));
    }

    /** @param list<array{name: string, email: string, amount: string, created_at: string}> $orders @return list<array{string, string}> */
    private function legacyPairs(array $orders): array
    {
        $nodes = array_map(static fn (array $o): array => [
            'id' => 'gid://shopify/Order/1',
            'legacyResourceId' => '1',
            'name' => $o['name'],
            'email' => $o['email'],
            'createdAt' => $o['created_at'],
            'displayFinancialStatus' => 'PAID',
            'totalPriceSet' => ['shopMoney' => ['amount' => $o['amount'], 'currencyCode' => 'USD']],
        ], $orders);

        $body = json_encode([
            'data' => [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => array_map(static fn (array $node): array => ['node' => $node], $nodes),
                ],
            ],
        ]);

        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $client = new Client('https://example.myshopify.com/admin/api/2026-01', 'token', $stack);
        $result = (new DuplicateOrderInsights($client))->findDuplicateOrders('2026-09-01', '2026-09-02');

        return $this->pairNames($result['pairs']);
    }

    /** @param list<array{name: string, email: string, amount: string, created_at: string}> $orders @return list<array{string, string}> */
    private function laravelPairs(array $orders): array
    {
        $flat = array_map(static fn (array $o): array => [
            'name' => $o['name'],
            'email' => $o['email'],
            'total_price' => $o['amount'],
            'created_at' => $o['created_at'],
        ], $orders);

        $pairs = (new DuplicateOrderAnalyzer())->analyze($flat);

        return $this->pairNames(array_map(static fn (array $p): array => [$p['first'], $p['second']], $pairs));
    }

    /** @param list<array{0: array<string, mixed>, 1: array<string, mixed>}> $pairs @return list<array{string, string}> */
    private function pairNames(array $pairs): array
    {
        $names = array_map(static fn (array $pair): array => [$pair[0]['name'], $pair[1]['name']], $pairs);
        sort($names);

        return $names;
    }
}
```

Save as `tests/Parity/DuplicateDetectorParityTest.php`.

- [ ] **Step 2: Run it to see the real mismatch**

Run: `vendor/bin/phpunit -c phpunit.parity.xml tests/Parity/DuplicateDetectorParityTest.php`
Expected: FAIL. Legacy returns only `[['#1001', '#1002']]` (the `#1001`/`#1003` and `#1002`/`#1003` pairs are 3600s and 3300s apart — outside the legacy 600-second window). Laravel returns all three pairs, because `DuplicateOrderAnalyzer` currently uses an 86400-second (24-hour) window instead of the real tool's 600-second (10-minute) window — confirmed by the Blade view's own copy at `laravel/resources/views/reports/duplicate-orders.blade.php:5`, which already says "no more than 10 minutes apart".

- [ ] **Step 3: Fix the analyzer**

In `laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php`, change:

```php
                    if ($gap !== null && $gap <= 86400) {
```

to:

```php
                    if ($gap !== null && $gap <= 600) {
```

- [ ] **Step 4: Update the analyzer's own unit tests to match the corrected window**

In `laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php`, replace the whole file with:

```php
<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\DuplicateOrderAnalyzer;
use PHPUnit\Framework\TestCase;

class DuplicateOrderAnalyzerTest extends TestCase
{
    public function test_it_matches_normalized_email_and_amount_within_ten_minutes(): void
    {
        $orders = [
            $this->order('#1', ' Jane@Example.com ', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('#2', 'jane@example.com', '50.00', '2026-09-01T10:10:00Z'),
            $this->order('#3', 'jane@example.com', '50.00', '2026-09-01T10:10:01Z'),
            $this->order('#4', 'jane@example.com', '75.00', '2026-09-01T10:05:00Z'),
            $this->order('#5', '', '50.00', '2026-09-01T10:05:00Z'),
            $this->order('#6', 'jane@example.com', '50.00', 'invalid'),
        ];

        $pairs = (new DuplicateOrderAnalyzer)->analyze($orders);

        $this->assertSame([['#1', '#2', 600], ['#2', '#3', 1]], array_map(fn (array $pair): array => [$pair['first']['name'], $pair['second']['name'], $pair['gap_seconds']], $pairs));
    }

    public function test_window_is_inclusive_of_exactly_ten_minutes_apart(): void
    {
        $orders = [
            $this->order('#1', 'jane@example.com', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('#2', 'jane@example.com', '50.00', '2026-09-01T10:10:00Z'),
        ];

        $pairs = (new DuplicateOrderAnalyzer)->analyze($orders);

        $this->assertCount(1, $pairs);
        $this->assertSame(600, $pairs[0]['gap_seconds']);
    }

    public function test_window_excludes_orders_one_second_past_ten_minutes(): void
    {
        $orders = [
            $this->order('#1', 'jane@example.com', '50.00', '2026-09-01T10:00:00Z'),
            $this->order('#2', 'jane@example.com', '50.00', '2026-09-01T10:10:01Z'),
        ];

        $this->assertSame([], (new DuplicateOrderAnalyzer)->analyze($orders));
    }

    /** @return array<string, mixed> */
    private function order(string $name, string $email, string $amount, string $createdAt): array
    {
        return ['name' => $name, 'email' => $email, 'total_price' => $amount, 'created_at' => $createdAt];
    }
}
```

- [ ] **Step 5: Run the Laravel unit test to confirm the fix**

Run: `cd laravel && vendor/bin/phpunit tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php && cd ..`
Expected: PASS — 3 tests.

- [ ] **Step 6: Run the differential test again to confirm parity**

Run: `vendor/bin/phpunit -c phpunit.parity.xml tests/Parity/DuplicateDetectorParityTest.php`
Expected: PASS.

- [ ] **Step 7: Record the finding in the new report**

Append a row to the table in `docs/parity-verification.md`:

```markdown
| `dupes` (Duplicate Detector) | `src/Shopify/GraphQL/DuplicateOrderInsights.php::findDuplicateOrders()` | `laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php::analyze()` | `tests/Parity/DuplicateDetectorParityTest.php` | Verified mismatch (fixed) | Laravel used an 86400s (24-hour) window instead of the real tool's 600s (10-minute) window — over-flagged pairs 10 minutes to 24 hours apart as duplicates. Fixed 2026-09-13; `laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php` updated to match. |
```

- [ ] **Step 8: Correct the old audit doc in place**

In `docs/laravel-rewrite.md`, find the `dupes` row:

```
| `dupes` | Duplicate Detector | Done | Case-insensitive email + exact total pairs within an inclusive 600-second window, full range pagination and visible truncation. |
```

Replace with:

```
| `dupes` | Duplicate Detector | Done | Case-insensitive email + exact total pairs within an inclusive 600-second window, full range pagination and visible truncation. Verified 2026-09-13 via differential test against legacy `DuplicateOrderInsights` — the shipped analyzer had regressed to an 86400-second window; fixed, see [`docs/parity-verification.md`](parity-verification.md). |
```

- [ ] **Step 9: Commit**

```bash
git add tests/Parity/DuplicateDetectorParityTest.php laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php docs/parity-verification.md docs/laravel-rewrite.md
git commit -m "fix(reports): correct Duplicate Detector window to match legacy 10-minute behavior

An independent differential test against legacy DuplicateOrderInsights
proved the Laravel analyzer used an 86400-second window instead of the
tool's real 600-second window (the Blade view's own copy already said
10 minutes). The audit doc's Done claim for dupes was unproven; now
verified and corrected in place."
```

---

### Task 3: Push to ShipStation differential test

**Files:**
- Create: `tests/Parity/PushToShipStationParityTest.php`
- Modify: `docs/parity-verification.md`
- Modify: `docs/laravel-platform-audit.md`

**Interfaces:**
- Consumes: legacy `\ShipStation::buildPayload(array $shopifyOrder): array` (constructed with `new \ShipStation(string $apiKey, string $apiSecret)`), and Laravel `\App\Integrations\ShipStation\ShipStationClient::buildOrderPayload(array $shopifyOrder): array` (constructed with `new \App\Integrations\ShipStation\ShipStationClient(string $apiKey, string $apiSecret)`). Both take the same snake_case Shopify order shape and neither makes a network call, so one shared fixture works for both.

- [ ] **Step 1: Write the differential test**

```php
<?php

declare(strict_types=1);

use App\Integrations\ShipStation\ShipStationClient;
use PHPUnit\Framework\TestCase;

final class PushToShipStationParityTest extends TestCase
{
    public function test_build_payload_matches_legacy_for_a_full_order(): void
    {
        $order = $this->order();

        $legacy = new \ShipStation('key', 'secret');
        $laravel = new ShipStationClient('key', 'secret');

        $this->assertSame($legacy->buildPayload($order), $laravel->buildOrderPayload($order));
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'order_number' => '#1001',
            'name' => '#1001',
            'created_at' => '2026-09-01T10:00:00Z',
            'email' => 'jane@example.com',
            'billing_address' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company' => null,
                'address1' => '123 Main St',
                'address2' => null,
                'city' => 'Austin',
                'province_code' => 'TX',
                'zip' => '78701',
                'country_code' => 'US',
                'phone' => '5551234567',
            ],
            'shipping_address' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company' => null,
                'address1' => '456 Oak Ave',
                'address2' => 'Apt 2',
                'city' => 'Austin',
                'province_code' => 'TX',
                'zip' => '78702',
                'country_code' => 'US',
                'phone' => '5559876543',
            ],
            'line_items' => [
                ['id' => 111, 'title' => 'Widget', 'sku' => 'WID-1', 'quantity' => 2, 'price' => '19.99'],
                ['id' => 112, 'title' => 'Gadget', 'sku' => 'GAD-1', 'quantity' => 1, 'price' => '9.99'],
            ],
            'shipping_lines' => [
                ['price' => '5.00'],
            ],
            'total_price' => '54.97',
            'total_tax' => '4.50',
        ];
    }
}
```

Save as `tests/Parity/PushToShipStationParityTest.php`.

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit -c phpunit.parity.xml tests/Parity/PushToShipStationParityTest.php`
Expected: PASS. `ShipStationClient::buildOrderPayload()` is a field-for-field port of legacy `ShipStation::buildPayload()`, so this confirms real parity rather than finding a gap.

- [ ] **Step 3: Record the confirmed match**

Append a row to the table in `docs/parity-verification.md`:

```markdown
| Push to ShipStation | `src/ShipStation.php::buildPayload()` | `laravel/app/Integrations/ShipStation/ShipStationClient.php::buildOrderPayload()` | `tests/Parity/PushToShipStationParityTest.php` | Verified match | Field-for-field identical payload construction confirmed against a full order fixture (addresses, line items, shipping, tax). |
```

- [ ] **Step 4: Correct the old audit doc in place**

In `docs/laravel-platform-audit.md`, find the Push row:

```
| Shopify → ShipStation push | Done | `PushOrderToShipStation` action, request validation, controller и tests | Idempotency key/duplicate-prevention hardening се преценява при реален production traffic |
```

Replace with:

```
| Shopify → ShipStation push | Done | `PushOrderToShipStation` action, request validation, controller и tests; `buildOrderPayload()` verified field-for-field against legacy `ShipStation::buildPayload()` via differential test 2026-09-13, see [`docs/parity-verification.md`](parity-verification.md) | Idempotency key/duplicate-prevention hardening се преценява при реален production traffic |
```

- [ ] **Step 5: Commit**

```bash
git add tests/Parity/PushToShipStationParityTest.php docs/parity-verification.md docs/laravel-platform-audit.md
git commit -m "test(parity): confirm Push to ShipStation payload matches legacy field-for-field"
```

---

## Out of scope for this plan

Scaling the harness to the remaining 70 tools, and the eventual legacy removal/cutover, are tracked separately in the spec's "Скалиране" and "Финален cutover" sections — not part of this pilot.
