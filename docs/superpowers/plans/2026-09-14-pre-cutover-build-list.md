# Pre-Cutover Build List Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the 13 product-decided gaps between the legacy PHP app and the Laravel rewrite that were marked "Verified mismatch (open) — needs a decision before cutover" in the parity audit, so the legacy system can be safely deleted and Laravel promoted to repo root.

**Architecture:** No new subsystems. Each task extends an existing Laravel controller/model/notification/view along the same lines already used elsewhere in this codebase (Spatie activity log already installed; Eloquent relationships already exist; Blade views already follow a flat-string layout convention — see `laravel/resources/views/layouts/app.blade.php`).

**Tech Stack:** Laravel (PHP 8.5), Spatie `laravel-activitylog` (already a dependency), PHPUnit/Pest per existing test conventions, Blade views (no new JS framework).

**Spec:** [`docs/parity-verification.md`](../../parity-verification.md) — each task cites the exact row. The legacy reference implementation for behavior being restored lives in the top-level `src/` tree (pre-rewrite PHP), cited per task.

## Global Constraints

- Every task must keep `vendor/bin/phpunit -c phpunit.parity.xml` (repo root) and `php artisan test --compact` (in `laravel/`) green — run both before each commit.
- Run `laravel/vendor/bin/pint --dirty --format agent` after editing any PHP file (per `laravel/CLAUDE.md`).
- Do not add new Composer/NPM dependencies — everything here is buildable with what's already installed (Spatie activitylog, Eloquent, Blade).
- Follow existing code conventions in the file you're editing (single-line/flat-array style is already used throughout `app/Http/Controllers`, `app/Models`, and the Blade views — don't reformat surrounding code to a different style).
- Every controller in this app resolves the active store the same way: `$activeStore = $request->attributes->get('activeStore'); $store = $request->user()->stores()->whereKey($activeStore->getKey())->firstOrFail();` — reuse this pattern, don't invent a new one.
- Each task's "done" bar is: a new/updated automated test proves the behavior, not manual clicking.

---

### Task 1: Action Log — instrument the 17 missing operator actions

**Files:**
- Modify: `laravel/app/Http/Controllers/IgnoredOrderController.php` (`store`, `destroy`, `bulkDestroy`, `import`)
- Modify: `laravel/app/Http/Controllers/Reports/PushToShipStationController.php` (the `store`/confirm action — find via `grep -n "class.*Controller" app/Http/Controllers/Reports/PushToShipStationController.php`)
- Modify: `laravel/app/Http/Controllers/PrintQueueController.php` (`store`, `destroy`, `clear` — check actual method names with `grep -n "public function" app/Http/Controllers/PrintQueueController.php`)
- Modify: `laravel/app/Http/Controllers/Reports/RunAuditController.php`'s `queue` action (maps to legacy `queue_audit`)
- Modify: `laravel/app/Http/Controllers/OrderNoteController.php` (`update`, maps to legacy `save_order_note`)
- Modify: `laravel/app/Http/Controllers/StoreSwitchController.php` or equivalent (`grep -rln "stores.active" app/Http/Controllers`, maps to legacy `switch_store`)
- Modify: `laravel/app/Http/Controllers/Admin/BannedIpController.php` (unban action, maps to legacy `unban_ip`)
- Modify: `laravel/app/Http/Controllers/Admin/SlackRulesController.php`, `Admin/EmailRulesController.php` (`update` methods, map to legacy `save_slack_rules`/`save_email_rules`)
- Modify: wherever cache-flush is implemented (`grep -rln "Cache::flush\|cache:clear" app/Http/Controllers app/Console`, maps to legacy `flush_cache`)
- Test: `laravel/tests/Feature/ActionLogInstrumentationTest.php`

**Interfaces:**
- Consumes: Spatie `activity()` helper (already used — see `grep -rn "activity(" app/Models/User.php app/Models/Store.php` for the existing call pattern this app uses for dirty-attribute logging).
- Produces: an `activity` log entry per action, `causedBy(auth()->user())`, `performedOn($store)` where a store is in scope, with `->withProperties([...])` carrying the same detail keys legacy recorded (see the exact legacy action name → detail-array mapping below — these names don't need to match the Laravel side's route names, they're just log labels, but keep them for continuity with the `laravel-todo.md`/parity doc cross-references):

| Legacy action name | Details recorded | Laravel call site |
|---|---|---|
| `ignore_order` | `order_number`, `reason` | `IgnoredOrderController::store()` |
| `unignore_order` | `order_number` | `IgnoredOrderController::destroy()` |
| `bulk_unignore_orders` | `count` | `IgnoredOrderController::bulkDestroy()` |
| `import_ignore_csv` | `count`, `reason` | `IgnoredOrderController::import()` |
| `push_to_shipstation` | `order_number`, `shipstation_order_id` (whatever the push result exposes) | `PushToShipStationController` confirm/store action |
| `pq_add` | `order_number` | `PrintQueueController::store()` |
| `pq_remove` | `order_number` | `PrintQueueController::destroy()` |
| `pq_clear` | `count` | `PrintQueueController::clear()` (or equivalent) |
| `queue_audit` | `start_date`, `end_date` | `RunAuditController::queue()` |
| `save_order_note` | `shopify_id`, `note_length` (not the note text — same as legacy, avoid logging PII/customer content verbatim) | `OrderNoteController::update()` |
| `switch_store` | `store_id` | store-switch controller |
| `unban_ip` | `ip` | `Admin\BannedIpController` unban action |
| `save_slack_rules` | the saved rules array | `Admin\SlackRulesController::update()` |
| `save_email_rules` | the saved rules array | `Admin\EmailRulesController::update()` |
| `flush_cache` | `store_id` | wherever cache flush lives |

- [ ] **Step 1: Write the failing test for one representative action (ignore order)**

```php
<?php

use App\Models\IgnoredOrder;
use App\Models\Store;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('ignoring an order is recorded in the activity log', function () {
    $user = User::factory()->create();
    $store = Store::factory()->create();
    $store->users()->attach($user);

    $this->actingAs($user)
        ->withSession(['active_store_id' => $store->getKey()])
        ->post(route('ignored-orders.store', ), ['order_number' => '1234', 'reason' => 'Test'])
        ->assertRedirect();

    expect(Activity::where('log_name', 'operator-actions')->where('description', 'ignore_order')->count())->toBe(1);
    $activity = Activity::where('description', 'ignore_order')->first();
    expect($activity->properties['order_number'])->toBe('1234');
    expect($activity->causer_id)->toBe($user->id);
});
```

Adjust the route/middleware setup to match how `activeStore` is actually resolved in this app's test helpers — check an existing passing feature test in `laravel/tests/Feature/IgnoredOrderControllerTest.php` for the correct `actingAs`/store-scoping boilerplate and copy that pattern instead of guessing session keys.

- [ ] **Step 2: Run it, confirm it fails** (`php artisan test --filter=activity_log`)

- [ ] **Step 3: Add the log call in `IgnoredOrderController::store()`**

```php
activity('operator-actions')->causedBy($request->user())->performedOn($this->storeModel($request))
    ->withProperties(['order_number' => $number, 'reason' => trim((string) ($request->validated('reason') ?? ''))])
    ->log('ignore_order');
```

Add the matching one-liner (same three-call shape: `causedBy` → `performedOn` → `withProperties` → `log('<name>')`) at each of the other 14 call sites listed in the Files/Interfaces table above, using that site's own already-available local variables for the properties.

- [ ] **Step 4: Run the one test, confirm it passes**

- [ ] **Step 5: Add one test per remaining action** in the same test file, following the same shape as Step 1 (call the route, assert one `Activity` row with the right `description` and `properties`).

- [ ] **Step 6: Run the full file, confirm all pass**

- [ ] **Step 7: Update `docs/parity-verification.md`** — change the "Action Log (actionlog)" row's Verdict from "Verified mismatch (open)" to "Verified mismatch (fixed)" and describe the instrumentation added.

- [ ] **Step 8: Commit**

```bash
cd laravel && vendor/bin/pint --dirty --format agent && php artisan test --compact
cd .. && git add laravel/app laravel/tests docs/parity-verification.md
git commit -m "feat(admin): instrument operator action log for 15 previously-unlogged actions"
```

---

### Task 2: Slack/Discord notification content — restore the full summary

**Files:**
- Modify: `laravel/app/Notifications/AuditSlackNotification.php`
- Modify: `laravel/app/Notifications/ScanSlackNotification.php`
- Modify: `laravel/app/Notifications/AuditDiscordNotification.php`
- Modify: `laravel/app/Notifications/ScanDiscordNotification.php`
- Modify: `laravel/app/Application/Reports/RunAudit.php:46-50` (the two `new AuditSlackNotification(...)` / `new AuditDiscordNotification(...)` call sites)
- Modify: `laravel/app/Application/Reports/RecordRun.php:38,42` (the two `new ScanSlackNotification(...)` / `new ScanDiscordNotification(...)` call sites)
- Test: `laravel/tests/Unit/Notifications/AuditSlackNotificationTest.php`, `laravel/tests/Unit/Notifications/AuditDiscordNotificationTest.php` (new files — check if `tests/Unit/Notifications/` already exists; if not, this is the first file there and that's fine)

**Interfaces:**
- Produces: `AuditSlackNotification::__construct(string $store, int $missing, string $period, string $mentions = '', int $found = 0, int $skipped = 0, int $ignored = 0, int $shipstationTotal = 0, float $durationSeconds = 0.0, array $missingOrders = [])` where `$missingOrders` is `list<array{name: string, total: float}>` (already exactly the shape `RunAudit::handle()` has in `$result['missing']` — no new data plumbing needed, just pass more of what's already computed).
- Same additive-parameter shape for `ScanSlackNotification`, `AuditDiscordNotification`, `ScanDiscordNotification` — Discord doesn't have `$mentions` (per the audit doc, Discord never had a mentions concept), keep that difference.

- [ ] **Step 1: Write the failing test for the Slack audit message**

```php
<?php

use App\Notifications\AuditSlackNotification;

test('audit slack notification includes the full summary', function () {
    $notification = new AuditSlackNotification(
        store: 'Test Store',
        missing: 2,
        period: '2026-01-01 → 2026-01-31',
        found: 10,
        skipped: 1,
        ignored: 0,
        shipstationTotal: 13,
        durationSeconds: 4.2,
        missingOrders: [
            ['name' => '#1001', 'total' => 49.99],
            ['name' => '#1002', 'total' => 120.0],
        ],
    );

    $message = $notification->toSlack((object) []);
    $payload = $message->toArray();

    expect(json_encode($payload))->toContain('#1001')->toContain('49.99')->toContain('#1002')->toContain('13')->toContain('4.2');
});
```

Run `php -r` or check `vendor/laravel/slack-notification-channel` (or Illuminate's built-in `SlackMessage`) docs for what `SlackMessage::toArray()`/`->headerBlock()`/`->contextBlock()`/`->sectionBlock()` actually expose — use `search-docs` (Laravel Boost MCP tool) with query `["slack notification block kit", "slack message fields"]` before writing the implementation, since the exact Block Kit builder API depends on the installed `illuminate/notifications` version.

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement `AuditSlackNotification::toSlack()` with the full summary**

```php
public function __construct(
    public string $store,
    public int $missing,
    public string $period,
    public string $mentions = '',
    public int $found = 0,
    public int $skipped = 0,
    public int $ignored = 0,
    public int $shipstationTotal = 0,
    public float $durationSeconds = 0.0,
    /** @var list<array{name: string, total: float}> */
    public array $missingOrders = [],
) {
    $this->onQueue('notifications');
}

public function toSlack(object $notifiable): SlackMessage
{
    $prefix = $this->mentions === '' ? '' : implode(' ', array_map(fn (string $id): string => "<@{$id}>", explode(' ', $this->mentions))).' ';
    $status = $this->missing > 0 ? 'danger' : 'good';
    $shown = array_slice($this->missingOrders, 0, 10);
    $lines = array_map(fn (array $o): string => "{$o['name']} - \${$o['total']}", $shown);
    if (count($this->missingOrders) > 10) {
        $lines[] = '...and '.(count($this->missingOrders) - 10).' more';
    }

    return (new SlackMessage)
        ->text("{$prefix}{$this->store}: Run Audit found {$this->missing} missing orders ({$this->period}).")
        ->headerBlock("{$this->store} — Run Audit")
        ->contextBlock(function ($block): void {
            $block->text("Period: {$this->period}");
        })
        ->sectionBlock(function ($block) use ($lines): void {
            $block->field("*Missing:* {$this->missing}")->markdown();
            $block->field("*Matched:* {$this->found}")->markdown();
            $block->field("*Skipped:* {$this->skipped}")->markdown();
            $block->field("*Ignored:* {$this->ignored}")->markdown();
            $block->field("*ShipStation total:* {$this->shipstationTotal}")->markdown();
            $block->field("*Duration:* {$this->durationSeconds}s")->markdown();
            if ($lines !== []) {
                $block->field(implode("\n", $lines))->markdown();
            }
        });
}
```

Treat this exact Block Kit builder syntax as a starting sketch, not gospel — verify against the docs lookup from Step 1 and adjust method names/chaining to match what's actually installed.

- [ ] **Step 4: Run the test, confirm it passes**

- [ ] **Step 5: Repeat steps 1-4 for `ScanSlackNotification`** (same fields minus `$missing`/`$period`, plus `$tool`/`$rows`), **`AuditDiscordNotification`** (build an `embeds` array in `toDiscord()`: `title`, `color` (green `0x2ECC71`/red `0xE74C3C` by `$missing > 0`), `fields` array, `description` = the same order-list text), and **`ScanDiscordNotification`**.

- [ ] **Step 6: Update the two call sites**

In `laravel/app/Application/Reports/RunAudit.php:46-50`, replace:
```php
Notification::route('slack', ...)->notify(new AuditSlackNotification($store->label, $missing, "{$start} → {$end}", $rules['mentions']));
```
with the widened call passing `found: count($result['found'])`, `skipped: count($result['skipped'])`, `ignored: count($result['ignored'])`, `shipstationTotal: count($shipstation)`, `durationSeconds: round(microtime(true) - $started, 3)`, `missingOrders: array_map(fn (array $o) => ['name' => (string) ($o['name'] ?? $o['order_number'] ?? ''), 'total' => (float) ($o['total_price'] ?? 0)], $result['missing'])`. Same for the Discord call, minus `$rules['mentions']`.

In `laravel/app/Application/Reports/RecordRun.php:38,42`, widen `ScanSlackNotification`/`ScanDiscordNotification` calls with whatever subset of `$attributes`/`$attributes['meta']` carries the equivalent scan-side summary (check what `RecordRun::handle()`'s `$attributes` argument actually contains at each scan call site via `grep -rn "runs->handle\|(new RecordRun" app/Domain/Reports app/Application/Reports` — most scan tools already pass a `meta` array with per-tool counts).

- [ ] **Step 7: Run the full Laravel suite + parity suite**

- [ ] **Step 8: Update `docs/parity-verification.md`** row "Slack/Discord audit & scan notification content" to "Verified mismatch (fixed)".

- [ ] **Step 9: Commit**

---

### Task 3: Email rules — global fallback recipient + full tool catalog

**Files:**
- Modify: `laravel/app/Models/Store.php:100-112` (`resolvedEmailRules()`)
- Modify: `laravel/app/Application/Reports/RecordRun.php:44-48` (recipient resolution)
- Modify: `laravel/app/Http/Controllers/Admin/EmailRulesController.php:14-24` (`edit()`)
- Modify: `laravel/database/migrations/` — add a migration for a new `stores.default_alert_email` column (nullable string)
- Modify: `laravel/resources/views/admin/email-rules.blade.php` (add the fallback-email field)
- Modify: `laravel/app/Http/Requests/EmailRulesRequest.php` (validate the new field)
- Create: `laravel/config/tool-catalog.php` mirroring legacy's `ToolRegistry::triggerCatalog()` (the `TRIGGER_CATALOG` constant read at `src/ToolRegistry.php:139` — copy every `'<tool_key>' => ['label' => ..., 'page' => ..., 'area' => ..., 'dependency' => ...]` entry, translating each `page` value to its Laravel route name using the mapping table in Task 7 below, since that table is the authoritative page→route mapping this whole plan already had to build once)
- Test: `laravel/tests/Feature/Admin/EmailRulesControllerTest.php` (extend existing), `laravel/tests/Unit/Models/StoreEmailRulesTest.php` (extend existing, if it exists — check with `find laravel/tests -iname "*EmailRules*"`)

**Interfaces:**
- Produces: `Store::resolvedEmailRules()` unchanged in shape, but `RecordRun::handle()` now falls back to `$store->default_alert_email` when `$emailRule['email'] === ''` instead of skipping the send outright.
- Produces: `config('tool-catalog')` returns `array<string, array{label: string, route: string, area: string, dependency: string}>` — same keys as legacy's `TRIGGER_CATALOG`, consumed by `EmailRulesController::edit()` to always render every tool, not just ones with existing `run_logs` rows.

- [ ] **Step 1: Write the failing test for the fallback**

```php
test('recording a run with a blank rule email falls back to the store default alert email', function () {
    $store = Store::factory()->create(['default_alert_email' => 'ops@example.com', 'email_rules' => ['scan_addresses' => ['mode' => 'immediate', 'threshold' => 1, 'include_zero' => false, 'email' => '']]]);

    (new RecordRun)->handle($store, ['tool' => 'scan_addresses', 'status' => 'issues_found', 'rows_found' => 3]);

    Notification::assertSentTo(new AnonymousNotifiable, ReportEmailNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.com');
});
```

Check `laravel/tests/Feature/*RecordRun*` or `*RunLog*` for the actual existing pattern used to assert `Notification::route('mail', ...)` sends in this codebase — `AnonymousNotifiable` assertion syntax varies by Laravel version, copy the working pattern from an existing passing test rather than guessing.

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Add the migration**

```bash
cd laravel && php artisan make:migration add_default_alert_email_to_stores_table --table=stores --no-interaction
```
```php
Schema::table('stores', function (Blueprint $table): void {
    $table->string('default_alert_email')->nullable()->after('email_rules');
});
```

- [ ] **Step 4: Add `default_alert_email` to `Store`'s fillable/casts as needed** (check the `#[Fillable(...)]` attribute pattern already used on the `Store` model and add the column name there).

- [ ] **Step 5: Update `RecordRun::handle()`**

```php
$recipient = $emailRule['email'] !== '' ? $emailRule['email'] : (string) ($store->default_alert_email ?? '');
if (($attributes['status'] ?? '') !== 'error' && $emailRule && $emailRule['mode'] === 'immediate' && $recipient !== '' && $rows >= $emailRule['threshold'] && ($rows > 0 || $emailRule['include_zero'])) {
    Notification::route('mail', $recipient)->notify(new ReportEmailNotification(...));
}
```

- [ ] **Step 6: Run the test, confirm it passes**

- [ ] **Step 7: Create `config/tool-catalog.php`** with all ~40 entries transcribed from `src/ToolRegistry.php`'s `TRIGGER_CATALOG` constant (already read in full during planning — copy every entry, translating `page` to the Laravel route name per Task 7's mapping table).

- [ ] **Step 8: Update `EmailRulesController::edit()`**

```php
public function edit(Request $request): View
{
    $store = $this->store($request);
    $rules = $store->resolvedEmailRules();
    foreach (array_keys(config('tool-catalog')) as $tool) {
        $rules[$tool] ??= ['mode' => 'off', 'threshold' => $tool === 'run_audit' ? 0 : 1, 'include_zero' => false, 'email' => ''];
    }

    return view('admin.email-rules', ['rules' => $rules, 'catalog' => config('tool-catalog'), 'defaultAlertEmail' => $store->default_alert_email]);
}
```

- [ ] **Step 9: Add the fallback-email field to `admin/email-rules.blade.php`** and its validation rule (`'default_alert_email' => ['nullable', 'email:rfc']`) to `EmailRulesRequest`, and save it in `EmailRulesController::update()`.

- [ ] **Step 10: Write a feature test asserting the fresh-store page now lists every catalog tool**, run it, confirm it passes.

- [ ] **Step 11: Run full suite, update `docs/parity-verification.md`** (both Email rules rows → fixed), **commit**.

---

### Task 4: Job Queue — sanitized result/error summary

**Files:**
- Modify: `laravel/app/Http/Controllers/JobQueueController.php:14-27`
- Modify: `laravel/resources/views/jobs/index.blade.php`
- Modify: `laravel/app/Models/AuditJob.php` if it doesn't already expose a summarized result (check with `cat laravel/app/Models/AuditJob.php`)
- Test: `laravel/tests/Feature/JobQueueControllerTest.php` (extend existing)

**Interfaces:**
- Produces: each row passed to the view gains `result_summary: array{rows_found: ?int, error: ?string}` — `error` is a short message (`$exception->getMessage()`, truncated to e.g. 200 chars), never the stack trace, matching the "safer than legacy" decision already made for this row.

- [ ] **Step 1: Write the failing test**

```php
test('job queue index shows a sanitized error summary for a failed job', function () {
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => "Something failed\nStack trace:\n#0 ...", 'failed_at' => now()]);

    $response = $this->actingAs($user)->get(route('jobs.index'));

    $response->assertOk()->assertSee('Something failed')->assertDontSee('Stack trace');
});
```
Adjust store/auth setup to match the existing `JobQueueControllerTest.php` boilerplate.

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Update `JobQueueController::index()`** to derive a first-line-only exception summary:

```php
$failed = DB::table('failed_jobs')->latest('id')->paginate(100, ['*'], 'failed')->through(function ($row) {
    $row->error_summary = $row->exception ? strtok((string) $row->exception, "\n") : null;
    return $row;
});
```

And for `auditJobs`, add a `result_summary` accessor on `AuditJob` (or compute inline) exposing counters already stored on the model (check what columns `AuditJob` actually has via `laravel/database/migrations` for its table — likely `rows_found`/`status`/`error` already exist since `RunAudit`'s queued path must write something there).

- [ ] **Step 4: Update `jobs/index.blade.php`** to render `$row->error_summary` in the failed-jobs table and the audit job's result counters in the audit-executions table.

- [ ] **Step 5: Run the test, confirm it passes; run full suite**

- [ ] **Step 6: Update `docs/parity-verification.md`**, **commit**.

---

### Task 5: Dashboard — restore missing operational indicators

**Files:**
- Modify: `laravel/app/Http/Controllers/DashboardController.php`
- Modify: `laravel/resources/views/dashboard.blade.php`
- Test: `laravel/tests/Feature/DashboardControllerTest.php` (extend existing)

**Interfaces:**
- Produces: the view payload gains `auditCadenceDays` (avg days between consecutive `report_date`s), `avgResolutionDays` (avg days an order stayed in a "missing" list before it stopped appearing — needs a per-order first/last-seen scan across `auditSnapshots.result.missing`), `staleIgnoredCount` (ignored orders whose `ignored_at` is older than e.g. 30 days and still present in the latest snapshot's missing list — same "why is this still ignored" signal legacy used), `oldestMissingAge` (days since the oldest currently-missing order's `created_at`), `missingByType` (group current `missing` rows by whatever type classifier is already used for Bundle Check — check `OrderTypeClassifier::classify()` for the field to group by), `sevenDayChart` (last 7 `auditSnapshots` rows_found, oldest first), and a `cache.flush` route wired to a button.

- [ ] **Step 1: Write the failing test for the 7-day chart data**, following the shape of the existing passing dashboard test (`grep -n "test\|it(" laravel/tests/Feature/DashboardControllerTest.php` to see the existing assertion style) — assert the view receives `sevenDayChart` with 7 entries oldest-first for a store seeded with 10 `AuditSnapshot` rows.

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Add `sevenDayChart` to `DashboardController::__invoke()`**

```php
'sevenDayChart' => $store->auditSnapshots()->where('tool', 'run_audit')->latest('report_date')->limit(7)->get()->reverse()->values()->map(fn ($s) => ['date' => $s->report_date->toDateString(), 'missing' => $s->rows_found]),
```

- [ ] **Step 4: Run test, confirm it passes. Repeat steps 1-4 for each remaining indicator** (`auditCadenceDays`, `avgResolutionDays`, `staleIgnoredCount`, `oldestMissingAge`, `missingByType`) — one test, one implementation addition, one pass, per indicator. For `avgResolutionDays`, the simplest correct approach: for each order number, find the earliest and latest `report_date` where it appears in `result.missing`, but it's no longer in the *current* latest snapshot's missing list (i.e., resolved); average `latest - earliest` in days across resolved orders from the last 30 `auditSnapshots`.

- [ ] **Step 5: Add a cache-flush button** — check if a flush route/action already exists anywhere in the app (`grep -rn "route.*cache\|Cache::flush" laravel/routes laravel/app/Http/Controllers`); if not, add `POST /admin/cache/flush` → a small controller calling `Cache::flush()` gated by the same `manage-administration` ability used elsewhere, logged via Task 1's `activity()` pattern as `flush_cache`.

- [ ] **Step 6: Update `dashboard.blade.php`** to render all new fields (cards + the 7-day chart as a simple inline bar chart, matching the flat-HTML style already used elsewhere in this app's Blade views — no charting library, just styled `<div>` bars sized by percentage, same approach `saved-reports/trends.blade.php` likely already uses for its own chart; check that file first).

- [ ] **Step 7: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 6: Trends — aggregates + repeat-offender list

**Files:**
- Modify: `laravel/app/Http/Controllers/ReportTrendController.php`
- Modify: `laravel/resources/views/saved-reports/trends.blade.php`
- Test: `laravel/tests/Feature/ReportTrendControllerTest.php` (extend existing)

**Interfaces:**
- Produces: view payload gains `avgMissing` (mean of `rows` `missing` column), `worstReport` (the snapshot with max `missing`, with its date), `clearReportCount` (count of snapshots with `missing === 0`), `uniqueMissingCount` (count of distinct order numbers across all in-range snapshots' `result.missing`), and `repeatOffenders` (`list<array{number: string, count: int}>` — order numbers appearing in 2+ in-range snapshots, sorted by count descending, each row's `number` deep-linkable to `ignored-orders.store` for one-click ignore, reusing the same bulk-ignore or single-ignore route already in this app).

- [ ] **Step 1: Write the failing test**

```php
test('trends page reports the repeat offender with the highest recurrence count', function () {
    // seed 3 AuditSnapshot rows where order #1001 appears in the missing list of all 3
    ...
    $response = $this->actingAs($user)->get(route('report-trends.index'));
    $response->assertViewHas('repeatOffenders', fn ($offenders) => $offenders[0]['number'] === '1001' && $offenders[0]['count'] === 3);
});
```

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement in `ReportTrendController::__invoke()`**

```php
$missingCounts = [];
foreach ($snapshots as $snapshot) {
    foreach ((array) ($snapshot->result['missing'] ?? []) as $order) {
        $number = (string) ($order['name'] ?? $order['order_number'] ?? '');
        if ($number !== '') {
            $missingCounts[$number] = ($missingCounts[$number] ?? 0) + 1;
        }
    }
}
arsort($missingCounts);
$repeatOffenders = collect($missingCounts)->filter(fn (int $count) => $count >= 2)->map(fn (int $count, string $number) => ['number' => $number, 'count' => $count])->values()->all();
$missingColumn = array_column($rows, 'missing');
$avgMissing = $missingColumn === [] ? 0.0 : array_sum($missingColumn) / count($missingColumn);
$worstReport = $snapshots->sortByDesc('rows_found')->first();
$clearReportCount = $snapshots->where('rows_found', 0)->count();
$uniqueMissingCount = count($missingCounts);
```

- [ ] **Step 4: Run test, confirm it passes**

- [ ] **Step 5: Update `trends.blade.php`** to render the 4 new stat cards and the repeat-offender table with an ignore button per row (`<form method="POST" action="{{ route('ignored-orders.store') }}">` with the order number prefilled, same as any other single-ignore form already in the app — check `resources/views/saved-reports/show.blade.php` or similar for the existing ignore-form markup to copy).

- [ ] **Step 6: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 7: Audit/Search hub navigation — full grouped hub

**Files:**
- Create: `laravel/config/audit-hub.php` and `laravel/config/search-hub.php` (or one `laravel/config/hubs.php` with both keys — follow whichever single-vs-split convention `laravel/config/order-types.php`/`tag-policy.php` already establish for this app's config files)
- Modify: `laravel/resources/views/layouts/app.blade.php:12-18` (the `$contextLinks` match block)
- Test: `laravel/tests/Feature/NavigationTest.php` (new — assert every route in the hub config resolves and every hub-listed route name exists via `Route::has()`)

**Interfaces:**
- Produces: `config('audit-hub')` returns `array<string, list<array{label: string, route: string}>>` keyed by section name (`'Core Audit'`, `'Order Issues'`, `'Address & Contact'`, `'Fulfillment'`, `'Carrier Analytics'`, `'Products & Inventory'`, `'Gift Cards'`, `'Fraud & Compliance'`), same for `config('search-hub')` (`'Orders'`, `'Customers & Tags'`, `'Metadata'`, `'Shipping'`).

Full page→route mapping (already resolved during planning by cross-referencing `src/ToolRegistry.php`'s `HUBS` constant against `php artisan route:list`):

**Audit hub:**
- Core Audit: Saved Reports→`saved-reports.index`, Run Audit→`reports.run-audit`, Trends→`report-trends.index`
- Order Issues: Duplicate Detector→`reports.duplicate-orders`, Refunds Tracker→`reports.refund-tracker`, Repeat Refunds→`reports.repeat-refunds`, Return/RMA Tracker→`reports.return-rma`, Returned Items→`reports.returned-items`, Orphan Detector→`reports.orphan-orders`, Active SS Conflicts→`reports.active-shipstation-conflicts`, SS Shipped/Shopify Unfulfilled→`reports.shipped-unfulfilled`, Order Edit History→`reports.order-edits`, Note Flags→`reports.note-flags`
- Address & Contact: Address Scanner→`reports.address-check`, Email Checker→`reports.email-check`, High-Value No Phone→`reports.high-value-no-phone`, Address Changes→`reports.address-changes`, Post-Ship Address Change→`reports.post-ship-address-changes`, Duplicate Shipping Addresses→`reports.duplicate-addresses`
- Fulfillment: Voided Shipments→`reports.voided-shipments`, Fulfillment SLA Breaches→`reports.fulfillment-sla`, Bundle Check→`reports.bundle-check`, Partial Fulfillment Stalls→`reports.partial-fulfillment`, On-Hold Stall→`reports.on-hold-stall`, Fulfilled Without Tracking→`reports.no-tracking`, Shipment Aging→`reports.shipment-aging`, Shipped Item Mismatch→`reports.item-mismatch`, Fulfilled Items Report→`reports.fulfilled-items`
- Carrier Analytics: Carrier Performance→`reports.carrier-performance`, Shipping Margin Erosion→`reports.shipping-margin`
- Products & Inventory: Product Completeness→`reports.product-completeness`, SKU Duplicates→`reports.sku-duplicates`, Inventory Oversell Risk→`reports.inventory-oversell`, Inventory Aging→`reports.inventory-aging`, Inventory Forecast→`reports.inventory-forecast`, Zombie Products→`reports.zombie-products`, Catalog Quality→`reports.catalog-quality`
- Gift Cards: Gift Cards→`reports.gift-cards`
- Fraud & Compliance: Billing≠Shipping Country→`reports.country-mismatch`, Discount Abuse→`reports.discount-abuse`, Tag Policy Audit→`reports.tag-policy`, Tax Audit→`reports.tax-audit`, Marketing Consent Audit→`reports.consent-audit`, Fraud Risk Report→`reports.fraud-risk`, Same IP Different Emails→`reports.same-ip`, Chargebacks/Disputes→`reports.disputes`

**Search hub:**
- Orders: Spot-check→`orders.spot-check`, Order Compare→`orders.compare`, Order Timeline→`orders.timeline`
- Customers & Tags: Customer Lookup→`customers.lookup`, Customer LTV→`reports.customer-ltv`, Tag Search→`orders.tag-search`, Tag Audit→`reports.tag-audit`
- Metadata: Metafields→`metafields.index`
- Shipping: Tracking Feed→`orders.tracking`, Packing Slip Preview→`orders.packing-slip`

- [ ] **Step 1: Write the failing test**

```php
test('every audit-hub and search-hub route resolves', function () {
    foreach (array_merge(config('audit-hub'), config('search-hub')) as $section => $links) {
        foreach ($links as $link) {
            expect(Route::has($link['route']))->toBeTrue("Missing route: {$link['route']} (section {$section})");
        }
    }
});
```

- [ ] **Step 2: Run it — it will fail because the config files don't exist yet**

- [ ] **Step 3: Create `laravel/config/audit-hub.php` and `laravel/config/search-hub.php`** transcribing the two mapping tables above verbatim into PHP arrays.

- [ ] **Step 4: Run the test, confirm it passes** (this proves every route name is real — if any typo exists in the mapping tables above, this test catches it now).

- [ ] **Step 5: Replace the `audit`/`search` cases in `app.blade.php`'s `$contextLinks` match** with a loop rendering the grouped sections instead of the current flat array — keep the existing `manage`/`settings` cases untouched (those aren't part of this task):

```blade
@if(in_array($group, ['audit', 'search'], true))
    @foreach(config($group === 'audit' ? 'audit-hub' : 'search-hub') as $section => $links)
        <div class="sidebar-section">{{ $section }}</div>
        <ul class="sidebar-nav">
            @foreach($links as $link)
                <li><a class="{{ request()->routeIs($link['route']) ? 'active' : '' }}" href="{{ route($link['route']) }}">{{ $link['label'] }}</a></li>
            @endforeach
        </ul>
    @endforeach
@endif
```
Fold this into the existing `@if($contextLinks)` block's structure rather than duplicating the whole sidebar — read the surrounding 10 lines of `app.blade.php` first (already done during planning; the `@can` gating on `orders.push.create`/`global-search`/`jobs.index`/`print-queue.index` and `admin.*` routes is for the `search`/`manage`/`settings` groups only, not `audit`, so it doesn't need to carry over into this loop).

- [ ] **Step 6: Manually load the dashboard in a browser (or `php artisan route:list` + eyeball) to confirm the sidebar renders all 8 audit sections and 4 search sections without breaking existing manage/settings navigation.**

- [ ] **Step 7: Run full suite, update `docs/parity-verification.md`** (both hub rows → fixed), **commit.**

---

### Task 8: Run Audit inline duplicates panel

**Files:**
- Create: `laravel/app/Domain/Reports/DuplicateOrderClusterer.php` (do not reuse `DuplicateOrderAnalyzer` — the audit doc's systemic finding explicitly says that class is the port of the unrelated `dupes` tool, not this panel; this needs its own small class)
- Modify: `laravel/app/Application/Reports/RunAudit.php:40-41` (pass the Shopify orders through the new clusterer, attach to the result)
- Modify: `laravel/resources/views/reports/run-audit.blade.php` (or wherever the run-audit result partial lives — check `resources/views/reports/` for the actual file name)
- Test: `laravel/tests/Unit/Domain/Reports/DuplicateOrderClustererTest.php`, extend `tests/Parity/` with a `RunAuditDuplicatesPanelParityTest.php` diffing against `Comparator::findDuplicates()` (`src/Comparator.php:518-550`, already read in full during planning)

**Interfaces:**
- Produces: `DuplicateOrderClusterer::cluster(array $shopifyOrders): list<array{email: string, amount: float, orders: list<array>}>` — groups by `strtolower(trim(email))|round(total_price, 0)`, requires 2+ orders in the group, then slides a 24-hour window over `created_at`-ascending orders keeping only sub-clusters where consecutive gaps are ≤86400s and the sub-cluster has 2+ orders, returning each qualifying sub-cluster's orders in **descending** `created_at` order (legacy does `array_reverse($cluster)` at `src/Comparator.php:547` since it built the cluster ascending — replicate that exact reversal, it's part of the parity contract).

- [ ] **Step 1: Write the failing parity test** (`tests/Parity/RunAuditDuplicatesPanelParityTest.php`, following the exact fixture-and-compare pattern already used by every other file in `tests/Parity/` — read `tests/Parity/DuplicateDetectorParityTest.php` first as the template, since it's the closest existing analog) with a fixture: 2 orders same email/amount 10 minutes apart (should cluster), 1 order same email/amount but 30 hours later (should NOT join that cluster), 1 unique order (should produce no cluster at all).

- [ ] **Step 2: Run it, confirm it fails** (class doesn't exist)

- [ ] **Step 3: Implement `DuplicateOrderClusterer::cluster()`** — port `src/Comparator.php:518-550` line-for-line into typed PHP (the legacy source was already read in full during planning; transcribe its grouping/windowing/reversal logic exactly, translating `$order['email']`/`$order['total_price']`/`$order['created_at']` array access to match whatever shape `RunAudit::handle()`'s `$shopify['orders']` already provides — same shape `AuditOrderAnalyzer::analyze()` consumes, so reuse those same field-access conventions).

- [ ] **Step 4: Run the parity test, confirm it passes**

- [ ] **Step 5: Wire it into `RunAudit::handle()`** — add `'duplicates' => $this->clusterer->cluster($shopify['orders'])` to the `AuditResult` (add a `duplicates` property to `AuditResult` DTO, check `laravel/app/Application/Reports/AuditResult.php` for its current constructor shape before adding a parameter).

- [ ] **Step 6: Render the panel in the run-audit view** — "N potential duplicates detected" summary line + an expandable list per cluster (email, amount, order links), matching the legacy panel's content per `views/run.php:87-103` (read that file if more visual detail is needed — the audit doc already describes it as "24h/rounded-amount clustering" with an "N potential duplicates detected" header, which is enough to build from).

- [ ] **Step 7: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 9: Run Audit checkbox bulk-ignore

**Files:**
- Modify: `laravel/routes/web.php` (new route, e.g. `POST /ignored-orders/bulk-store` — check existing `ignored-orders.*` route group for naming convention to match)
- Modify: `laravel/app/Http/Controllers/IgnoredOrderController.php` (new `bulkStore` method)
- Create: `laravel/app/Http/Requests/BulkIgnoreOrdersRequest.php`
- Modify: the 6 report views legacy wires this into — for the Laravel port, start with just `run-audit.blade.php`'s missing-orders table (the other 5 legacy pages — `refunds.php`/`emailcheck.php`/`addrcheck.php`/`trends.php`/`ignored.php` — map to `reports.refund-tracker`, `reports.email-check`, `reports.address-check`, `report-trends.index` (already getting a form in Task 6), and `ignored-orders.index`; add the same checkbox+form partial to each of those five views too)
- Create: `laravel/resources/views/partials/bulk-ignore-form.blade.php` (shared checkbox+reason+submit partial, included by all 6 views — this is the right place for a shared Blade partial since the exact same markup repeats 6 times)
- Test: `laravel/tests/Feature/IgnoredOrderControllerTest.php` (extend), `tests/Parity/BulkIgnoreParityTest.php` diffing against `Actions::buildBulkIgnoreEntries()` (`src/Actions.php:104+`, already read during planning)

**Interfaces:**
- Produces: `IgnoredOrderController::bulkStore(BulkIgnoreOrdersRequest $request): RedirectResponse` — validates `order_numbers: array`, `reason: nullable|string`, normalizes each number the same way `IgnoredOrderController::normalize()` already does (reuse that private method, don't duplicate the regex), `updateOrCreate`s one `IgnoredOrder` row per number with the shared reason, skips empty-after-normalization numbers (matching legacy's `buildBulkIgnoreEntries()` behavior exactly — read `src/Actions.php:104-120` for the precise skip/reason-defaulting rule and replicate it).

- [ ] **Step 1: Write the failing parity test for the entry-building logic** — extract the normalize+skip+reason logic into a small pure method (`IgnoredOrderController::buildBulkEntries(array $rawNumbers, string $reason): array<string, array{reason: string}>` or similar) so it's diff-testable the same way every other file in `tests/Parity/` works, following `tests/Parity/IgnoreOrderParityTest.php`'s existing pattern (already covers single-ignore normalization — extend its fixture set to bulk).

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement `bulkStore()`**

```php
public function bulkStore(BulkIgnoreOrdersRequest $request): RedirectResponse
{
    $store = $this->storeModel($request);
    $reason = trim((string) ($request->validated('reason') ?? ''));
    $count = 0;
    foreach ((array) $request->validated('order_numbers') as $raw) {
        $number = $this->normalize((string) $raw);
        if ($number === '') {
            continue;
        }
        $store->ignoredOrders()->updateOrCreate(['order_number' => $number], ['reason' => $reason, 'ignored_at' => today()]);
        $count++;
    }

    return back()->with('status', "{$count} orders ignored.");
}
```

- [ ] **Step 4: Add the route** in `routes/web.php` next to the existing `ignored-orders.*` group: `Route::post('ignored-orders/bulk', [IgnoredOrderController::class, 'bulkStore'])->name('ignored-orders.bulk-store');`

- [ ] **Step 5: Run the test, confirm it passes**

- [ ] **Step 6: Create `partials/bulk-ignore-form.blade.php`** — a `<form>` wrapping the existing missing-orders `<table>` markup with a checkbox per row (`name="order_numbers[]"`), a shared reason `<input>`, and a submit button posting to `ignored-orders.bulk-store`. Include it in `run-audit.blade.php` first, get it working, then include it in the other 5 views listed above.

- [ ] **Step 7: Add a feature test posting to the new route with 3 numbers (one blank/invalid) and asserting 2 `IgnoredOrder` rows created with the shared reason.**

- [ ] **Step 8: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 10: Saved Reports drill-down

**Files:**
- Modify: `laravel/app/Http/Controllers/SavedReportController.php:21-24` (`show()`)
- Modify: `laravel/resources/views/saved-reports/show.blade.php`
- Test: `laravel/tests/Feature/SavedReportControllerTest.php` (extend existing)

**Interfaces:**
- Produces: `show()`'s view payload gains `history` (last 30 `auditSnapshots` for the chart, oldest first — same shape as Task 5's `sevenDayChart` but 30 rows), `recurrenceCounts` (`array<string, int>` — for each order number in this snapshot's `missing` list, how many of the last 30 snapshots it also appears in, reusing the same counting approach as Task 6's `repeatOffenders`), and action links per row (Shopify/ShipStation/Spot-check/Timeline — these are just `route()` calls to already-existing routes: `orders.spot-check`, `orders.timeline`, plus the raw Shopify admin URL and ShipStation deep link, both of which are already constructed elsewhere in this app — check `app/Domain/Orders/TrackingFeedBuilder.php` or `PackingSlipBuilder.php` for the existing ShipStation URL-building convention to reuse rather than re-deriving it), an ignore-button per row (reuse the single-ignore form pattern), and a same-day re-audit button (`POST` to `reports.run-audit.store` with `start_date`/`end_date` both set to this snapshot's `report_date`).

- [ ] **Step 1: Write the failing test**

```php
test('saved report show page includes recurrence counts for repeat-missing orders', function () {
    // seed 3 snapshots where order #1001 is missing in all 3, viewing the latest
    $response = $this->actingAs($user)->get(route('saved-reports.show', $latestSnapshot));
    $response->assertViewHas('recurrenceCounts', fn ($counts) => $counts['1001'] === 3);
});
```

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement in `SavedReportController::show()`**

```php
public function show(Request $request, int $report): View
{
    $snapshot = $this->report($request, $report);
    $store = $this->store($request);
    $history = $store->auditSnapshots()->where('tool', 'run_audit')->latest('report_date')->limit(30)->get()->reverse()->values();
    $recurrenceCounts = [];
    foreach ($history as $historical) {
        foreach ((array) ($historical->result['missing'] ?? []) as $order) {
            $number = (string) ($order['name'] ?? $order['order_number'] ?? '');
            if ($number !== '') {
                $recurrenceCounts[$number] = ($recurrenceCounts[$number] ?? 0) + 1;
            }
        }
    }

    return view('saved-reports.show', compact('snapshot', 'history', 'recurrenceCounts') + ['report' => $snapshot]);
}
```

- [ ] **Step 4: Run test, confirm it passes**

- [ ] **Step 5: Update `show.blade.php`** to render the 30-report chart (reuse whatever inline-bar-chart markup Task 5 introduced for consistency), a recurrence badge per row (`@if(($recurrenceCounts[$order['number']] ?? 1) >= 3) <span class="badge-hot">Hot</span> @elseif >= 2 <span class="badge-warning">Recurring</span> @endif`), the Shopify/ShipStation/Spot-check/Timeline action links, an ignore button, and the re-audit button.

- [ ] **Step 6: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 11: Ignored Orders — recurrence context

**Files:**
- Modify: `laravel/app/Http/Controllers/IgnoredOrderController.php:15-18` (`index()`)
- Modify: `laravel/resources/views/ignored-orders/index.blade.php`
- Test: `laravel/tests/Feature/IgnoredOrderControllerTest.php` (extend existing)

**Interfaces:**
- Produces: `index()`'s view payload gains `recurrenceCounts` — same computation as Task 10, but scoped to however many recent `auditSnapshots` are cheap to scan (reuse the same 30-snapshot window for consistency across the app rather than inventing a different window size here).

- [ ] **Step 1: Write the failing test** — seed one ignored order that also appears in 3 recent snapshots' missing lists, assert `index()`'s view has a `recurrenceCounts` entry `>= 3` for it.

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement** — extract the recurrence-counting loop from Task 10 into a small reusable method (e.g. a static helper on a new tiny class `App\Domain\Reports\RecurrenceCounter` or a private method duplicated twice is also fine here since it's ~6 lines — prefer extracting once Task 10 and this task both need it, per this app's DRY convention) and call it from `IgnoredOrderController::index()`.

- [ ] **Step 4: Run test, confirm it passes**

- [ ] **Step 5: Update `ignored-orders/index.blade.php`** to show the same hot/recurring badge convention introduced in Task 10.

- [ ] **Step 6: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 12: Push Log / Run History — server-side `q` filter

**Files:**
- Modify: `laravel/app/Http/Controllers/PushLogController.php`
- Modify: `laravel/app/Http/Controllers/RunLogController.php`
- Modify: `laravel/resources/views/push-logs/index.blade.php`, `laravel/resources/views/run-logs/index.blade.php`
- Test: `laravel/tests/Feature/PushLogControllerTest.php`, `laravel/tests/Feature/RunLogControllerTest.php` (extend existing)

**Interfaces:**
- Produces: both controllers accept `?q=` query string; `PushLogController` filters by `order_number LIKE %q%` or `shopify_id`/`shipstation_id` exact match if `q` is all-digits; `RunLogController` filters by `tool LIKE %q%` OR `status = q` OR `error LIKE %q%` OR `start_date <= q <= end_date`.

- [ ] **Step 1: Write the failing test for Push Log**

```php
test('push log can be filtered by order number', function () {
    $store->pushLogs()->create(['order_number' => '1001', ...]);
    $store->pushLogs()->create(['order_number' => '2002', ...]);

    $response = $this->actingAs($user)->get(route('push-logs.index', ['q' => '1001']));

    $response->assertViewHas('pushes', fn ($pushes) => $pushes->count() === 1 && $pushes->first()->order_number === '1001');
});
```

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Implement in `PushLogController::__invoke()`**

```php
$query = $store->pushLogs()->latest('pushed_at')->latest('id');
if ($request->filled('q')) {
    $q = (string) $request->string('q');
    $query->where(fn ($sub) => $sub->where('order_number', 'like', "%{$q}%")->orWhere('shopify_id', $q)->orWhere('shipstation_id', $q));
}

return view('push-logs.index', ['pushes' => $query->paginate(100)->withQueryString(), 'q' => $request->string('q')->toString()]);
```
(Verify the exact column names on `PushLog` via `grep -n "Fillable" app/Models/PushLog.php` before writing the `orWhere` clauses.)

- [ ] **Step 4: Run test, confirm it passes**

- [ ] **Step 5: Repeat steps 1-4 for `RunLogController`** with its own filter set (`tool`, `status`, `error`, date range) — check `RunLog`'s actual columns (already read: `tool`, `status`, `start_date`, `end_date`, `duration_seconds`, `scanned`, `rows_found`, `error`, `meta`).

- [ ] **Step 6: Add a `q` search box to both index views**, preserving it across pagination via `->withQueryString()`.

- [ ] **Step 7: Run full suite, update `docs/parity-verification.md`, commit.**

---

### Task 13: High-Value No Phone — "all currencies" option

**Files:**
- Modify: `laravel/app/Domain/Reports/HighValueNoPhoneAnalyzer.php:8` (`analyze()` signature)
- Modify: `laravel/app/Application/Reports/RunHighValueNoPhoneReport.php:13`
- Modify: `laravel/app/Http/Requests/HighValueNoPhoneRequest.php:30`
- Modify: `laravel/app/Http/Controllers/Reports/HighValueNoPhoneController.php:23,34`
- Modify: `laravel/resources/views/reports/high-value-no-phone.blade.php`
- Modify: `tests/Parity/HighValueNoPhoneParityTest.php` (add an "all currencies" case)

**Interfaces:**
- Produces: `HighValueNoPhoneAnalyzer::analyze(array $orders, float $minimum, ?string $currency): array` — `null` (or a sentinel string `'ALL'`, pick whichever this codebase's other "no filter" conventions use; check `HighValueNoPhoneRequest` siblings for precedent, otherwise default to `null`) skips the `$orderCurrency !== $currency` check entirely instead of comparing.

- [ ] **Step 1: Write the failing parity test** — add a case to `tests/Parity/HighValueNoPhoneParityTest.php`: two high-value no-phone orders in different currencies (USD, EUR), call `analyze($orders, 200, null)`, assert both rows are returned (legacy has no currency concept at all, so "all currencies" is the parity-correct default to diff against — this is a new Laravel-only capability, not something legacy has, so the "parity" test here is really just a regression test proving `null` means unfiltered; keep it in the parity file only because that's where this analyzer's other tests already live, not because there's a legacy counterpart for this specific case).

- [ ] **Step 2: Run it, confirm it fails**

- [ ] **Step 3: Update `HighValueNoPhoneAnalyzer::analyze()`**

```php
public function analyze(array $orders, float $minimum, ?string $currency): array
{
    ...
    if ($phone !== '' || $total < $minimum || ($currency !== null && $orderCurrency !== $currency)) {
        continue;
    }
    ...
}
```

- [ ] **Step 4: Run test, confirm it passes**

- [ ] **Step 5: Thread the nullable currency through `RunHighValueNoPhoneReport::handle()` and `HighValueNoPhoneRequest`** — change the validation rule to `'currency' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Za-z]{3}\z/']` and in `HighValueNoPhoneController`, treat an empty/`"ALL"` submitted value as `null` before calling `$report->handle(...)`.

- [ ] **Step 6: Add an "All currencies" option to the `<select>` in `high-value-no-phone.blade.php`**, defaulting still to `USD` (per the user's decision, only add the option — don't change the default).

- [ ] **Step 7: Run full suite (including `tests/Parity/`), update `docs/parity-verification.md`, commit.**

---

## Final Step: Close out the audit doc

After all 13 tasks are merged, update `docs/parity-verification.md`'s "Прогрес" summary table (0 rows should remain "Verified mismatch (open)" that were on this list — the 5 explicitly-accepted-as-is rows from the user's decision session stay "open" with a note that they were product-accepted, not fixed) and update `docs/laravel-todo.md` to reflect the cutover is now unblocked. Only after this should the legacy-deletion + root-promotion step (already safety-tagged as `legacy-final`) proceed — that is a separate, explicit action requiring its own go-ahead, not part of this plan.
