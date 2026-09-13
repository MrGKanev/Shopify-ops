# Laravel rewrite — platform and extras audit

Последно обновяване: **2026-09-11**.

Този checklist покрива всичко извън 72-та видими tools: authentication,
notifications, health, persistence, jobs, exports, observability, deployment и
security. Feature matrix-ът остава в [Laravel rewrite плана](laravel-rewrite.md),
а legacy test mapping-ът е в [test audit-а](laravel-test-audit.md).

Статуси: **Done** означава работещ production contract с тестове; **Foundation**
означава наличен Laravel scaffold, но липсва крайният workflow; **Todo** означава
че работата още не е започната. Framework config файл сам по себе си не прави
capability-то готово.

## Identity и достъп

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Password login/logout | Done | Session auth, credential validation, session regeneration и login throttle | Финална production proxy/TLS проверка се следи в hardening |
| Roles и authorization | Done | Viewer/operator/admin gates, route protection и admin authorization tests | Пълната legacy action-permission method mapping остава в test audit-а |
| Multi-store access | Done | Membership, active-store middleware и store-scoped credentials | Всички бъдещи routes/jobs задължително получават isolation тест |
| First administrator | Done | Fresh-install Artisan command с atomic validation, включена в [deployment runbook](laravel-deployment-runbook.md) | — |
| Google OAuth | Done | Socialite redirect/callback, `GoogleIdentityPolicy` allowed-domain policy, existing/new-user policy, disabled-config UX, session regeneration, `throttle:oauth` rate limit, safe error messages и tests без реална мрежа | — |
| Lockout и banned IP management | Done | `LoginAttempt` модел, `LoginThrottle` service (persistent IP lockout след 3 неуспешни опита), `BannedIpController` admin UX и tests | Trusted-proxy IP resolution и audit log за unban се преценяват отделно при нужда |
| Security headers/cookies/proxy | Partial | CSP, frame/content-type/referrer/permissions headers, conditional HSTS, secure-cookie config, explicit trusted proxies, production config validation и tests | Проверка зад реалния production TLS proxy |

## Email, SMTP и notifications

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| SMTP transport | Partial | Admin diagnostic показва mailer/from status и изпраща валидирано test писмо през SMTP; 10-second timeout, rate limit, safe failure log и fake tests | Production secrets/deployment configuration и реален staging delivery smoke test |
| Audit email notifications | Done | `ReportEmailNotification` mailable, изпратено през `RecordRun` за immediate-mode правила (покрива и `run_audit`), subject/count wording | Dedicated HTML/text template design и duplicate-delivery hardening се преценяват отделно |
| CSV email attachments | Done | `CsvExporter::content()` (reuses safe-filename/formula-escaping от download), `ReportEmailNotification` attach-when-present, `RecordRun`/`RunAudit` подават missing-orders CSV на immediate-mode audit email; 5,000-row cap | Проширяване към останалите tools извън `run_audit` се прави при реална нужда |
| Per-tool email rules | Done | `EmailRulesController`/`EmailRulesRequest`, per-store `email_rules` JSON (mode off/immediate/digest, threshold, include-zero, recipient), `resolvedEmailRules()` validation | — |
| Daily email digest | Done | `reports:email-digest` Artisan command, groupира по recipient през `resolvedEmailRules()`, `ReportDigestNotification`, `Schedule::command(...)->dailyAt('08:00')->withoutOverlapping()` | Timezone/day-boundary edge cases и idempotency tests се разширяват при нужда |
| Slack notifications | Done | Per-store `slack_rules` JSON, webhook channel adapter, audit/scan notifications, @mention rules, admin test-send endpoint и tests | — |
| Discord notifications | Done | Per-store `discord_rules` JSON, webhook channel adapter, audit/scan notifications, admin test-send endpoint и tests | — |
| Notification delivery log | Done | `notification_deliveries` DB модел, `LogNotificationDelivery` listener на `NotificationSent`/`NotificationFailed` (единна точка за mail/Slack/Discord), channel/recipient (само за mail)/status/error category; webhook URLs никога не се пишат | Admin преглед на историята се преценява отделно, ако стане нужен |

## Health, metrics и observability

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Liveness endpoint | Done | Laravel `/up` и feature test | Да остане евтин, без външни API calls |
| Readiness endpoint | Done | `/ready` проверява database, cache, queue configuration и worker-heartbeat freshness и връща 200/503 без secrets | — |
| API Health page | Done | Admin-only live checks за Shopify shop/scopes, requested/returned API version mismatch и ShipStation auth, per-store isolation, latency, safe errors и rate limit; `flowHealth()` monitor върху persisted run history (healthy/attention summary по tool, errors count, last run/error), рендериран в "Report flow history" и покрит от `test_page_summarizes_store_scoped_persisted_flow_history` | — |
| Webhook Health page | Done | `CheckWebhookHealth` use case, `WebhookHealthController` admin view, per-store results и tests | — |
| Metrics endpoint | Done | `GET /metrics`, Prometheus text-exposition format, `hash_equals` bearer-token auth (404 unconfigured, 401 wrong/missing token), `checker_runs_total`/`checker_notification_deliveries_total`/`checker_failed_jobs_total`/`checker_queue_pending_jobs`, tests потвърждаващи липса на PII/secrets в body | Dashboards/alerting остават в Production observability реда |
| Structured application logs | Done | `daily`/180-day production retention, request/run/store/tool context чрез Laravel Context, status/category полета, recursive secret/PII redaction и URL query stripping с tests | — |
| Run history | Done | `run_logs` DB модел, `RecordRun` persist action (retention до 500 записа/store), `RunLogController` екран; свързан към **всичките 46** report/audit controllers чрез споделен `RecordsReportRun` trait — status, counts, duration, range, store/tool, newest-first, authorization | Typed error category (в момента free-text `error` поле) се преценява отделно |
| Action audit log | Done | Spatie activity log (`create_activity_log_table` migration, `activitylog:clean` scheduled pruning), `ActionLogController` admin view и tests | — |
| Operational alerts | Done | Slack/Discord alerts за failed jobs/notifications, queue latency >5 min, scheduler heartbeat >5 min и 3 API/report failures за 15 min; 15-min deduplication и self-alert loop guard | External monitoring на `/ready` е добра production практика; доставчикът и deployment-ът са избор на owner-а |

## Jobs, scheduler и recovery

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Queue storage | Done | Production connection избран: `redis` управляван от Horizon (`config/horizon.php`, admin-only `/admin/horizon`, `horizon:snapshot` на 5 мин); `.env.example` и [deployment runbook-а](laravel-deployment-runbook.md) обновени с Horizon supervisor config | Health visibility отделно от Horizon dashboard-а не се планира — Horizon вече покрива queue health |
| Audit jobs | Done | `RunAuditJob` (ShouldQueue), unique store/range dispatch, explicit retries/timeouts/backoff и store-scoped queued/running/completed/failed history в Job Queue UI | — |
| Idempotency/concurrency | Done | `RunAuditJob implements ShouldBeUnique` — `uniqueId()` по store/range, `uniqueFor()` 1-час safety-net lock (Laravel cache lock, atomic claim/release around job lifecycle), tests в `RunAuditJobTest` доказват дублиран dispatch не се queue-ва повторно докато първият е pending/running, докато различен range/store продължава да се queue-ва | — |
| Failed-job recovery | Done | `JobQueueController` (`/jobs`, `can:run-audits`) — вече съществуваше преди тази сесия: pending + failed jobs listing, retry/forget през вградените `queue:retry`/`queue:forget`, безопасно (никога не показва exception текст на този по-широк-достъп екран), tests (`JobQueueControllerTest`). Сесията добави, после махна дублиращ `Admin\FailedJobController` — консолидирано в едно място. При `QUEUE_CONNECTION=redis` Pending секцията вече линква към `/admin/horizon` за admin потребители (`viewHorizon` gate) или показва admin-only notice за останалите, вместо празна `jobs` таблица | — |
| Scheduler | Done | `routes/console.php` — `reports:email-digest`, `activitylog:clean`, `health:*-heartbeat`, `health:check`, `model:prune`, `backup:run`/`monitor`/`clean`, `horizon:snapshot`; всички cron-критични с `withoutOverlapping()` | Timezone policy documentation и single-server решение (locking driver) се потвърждават в deployment runbook-а |
| Worker deployment | Done | Supervisor config template (`queue:work`/Horizon вариант), `--max-time`/`autorestart` graceful cycling, `queue:restart` deploy hook — виж [deployment runbook](laravel-deployment-runbook.md) | — |

## Persistence, settings и state

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Users/stores/credentials | Done | DB models/migrations, encrypted integration credentials и admin CRUD | Backup/restore и production secret rotation instructions |
| Notification settings | Done | Per-store `slack_rules`/`discord_rules`/`email_rules` JSON с per-tool mode, mentions, threshold/recipient override и validation | — |
| Sidebar preferences | Done decision | `laravel-test-audit.md` вече реши това при `SidebarSettingsTest.php` — Laravel навигацията е плосък top-nav без sidebar-section концепция, за която да се закачи тази настройка | — |
| Ignore/unignore orders | Done | `IgnoredOrder` модел, `IgnoredOrderController`/requests (single, bulk, import), normalization и authorization | — |
| Audit snapshots | Done | `AuditSnapshot` модел, `updateOrCreate` per store/tool/date в `RunAudit::handle()`, Saved Reports view | — |
| Report persistence | Done | `SavedReportController` и tests | — |
| Cache policy | Done decision | Production Redis с unique deployment prefix; cache-ът е само за locks, unique jobs и health heartbeats; Shopify/ShipStation/report reads остават fresh | Application-data TTL/invalidation policy се добавя само при измерена нужда |
| Legacy runtime state | Done decision | Изрично няма import на legacy users/jobs/logs/cache/reports/settings | Cutover checklist да потвърди чиста база и липса на runtime dependency |

## Exports и mutable workflows

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| CSV/report downloads | Done | Общ `CsvExporter` service, ползван от export() методите на report controllers (store-scoped, safe headers) | Формален security review за formula-injection escaping и large-dataset streaming се препоръчва отделно |
| Shopify → ShipStation push | Done | `PushOrderToShipStation` action, request validation, controller и tests; `buildOrderPayload()` verified field-for-field against legacy `ShipStation::buildPayload()` via differential test 2026-09-13, see [`docs/parity-verification.md`](parity-verification.md) | Idempotency key/duplicate-prevention hardening се преценява при реален production traffic |
| Shopify order note update | Done | `SaveOrderNote` action, request validation, controller и tests | — |
| Print queue | Done | `PrintQueueItem` модел, `PrintQueueController`/requests — persisted enqueue/order/remove, authorization | — |

## Configuration, CI и deployment

| Capability | Статус | Налично | Нужно за Done |
|---|---|---|---|
| Application install | Done | Composer/NPM Laravel app и install command | Production runbook с migrations, assets, storage link и initial admin/store setup |
| Configuration validation | Done | `CheckConfiguration` проверява app/cache/queue, Google OAuth, store credentials, order types/tag policy, mail/notifications и production trusted-proxy/secure-cookie/HSTS contract | — |
| CI checks | Done | Laravel CI изпълнява PHPUnit, Larastan level 5, Pint, Composer audit и frontend build/audit; Larastan scope покрива Application, Domain и Integrations без baseline | Разширяване към HTTP/Models и по-високо analysis ниво се прави постепенно без отслабване на gate-а |
| Backup and restore | Partial | `spatie/laravel-backup`, scheduled run/monitor/clean, admin list/download, documented destination ownership, retention policy и safe isolated restore procedure с evidence checklist | Реална production-like restore rehearsal |
| Deployment runbook | Done | [`docs/laravel-deployment-runbook.md`](laravel-deployment-runbook.md) — write freeze, .env decision table, supervisor/cron, routine deploy стъпки, smoke checks, fix-forward policy | Изпълнение на реален fresh-install + deploy repetition остава в UAT реда |
| Production observability | Foundation | Sentry SDK (`config/sentry.php`, admin-scrubbed `SentryEventSanitizer`, disabled без `SENTRY_LARAVEL_DSN`), Laravel Pulse (`/admin/pulse`, admin-only), Laravel Horizon (`/admin/horizon`, admin-only `viewHorizon` gate, `horizon:snapshot` на всеки 5 мин, вече активен production queue избор — виж "Queue storage") и Prometheus `/metrics` вече са инсталирани и wired | Production решение: `SENTRY_LARAVEL_DSN` стойност и избор на кой stack получава scrape/alerting за `/metrics` — потребителят реши да отложи тази конфигурация до по-късно |
| UAT и cutover rehearsal | Foundation | [`docs/laravel-uat-cutover-checklist.md`](laravel-uat-cutover-checklist.md) — golden fixtures план, rehearsal процедура, sign-off evidence и irreversible cutover checklist документирани | Действителното изпълнение на двете production-like репетиции — дата не е насрочена |

## Общ release gate за extras

- [ ] Всеки ред е `Done` или има изрично прието отклонение.
- [ ] Няма реални Shopify, ShipStation, SMTP, Slack, Discord или Google заявки в tests.
- [ ] Secrets и PII не присъстват в responses, logs, metrics, jobs или export filenames.
- [ ] Всеки background side effect е idempotent и има retry/recovery test.
- [ ] Health и metrics работят при деградирала външна система и не разкриват credentials.
- [ ] Fresh install и production-like deployment са повторени по runbook.
- [ ] Test audit-ът е затворен за свързаните legacy files.
