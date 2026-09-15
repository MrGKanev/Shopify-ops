# Shopify Ops

Shopify Ops is a self-hosted operations console for Shopify and ShipStation. It combines live order lookup, operational audits, saved reports, queue management, notifications, health checks, backups, and multi-store access in one Laravel application.

Shopify is the primary integration. ShipStation is optional for Shopify-only tools, but required for cross-platform audits, shipment checks, packing slips, tracking, and pushing orders.

## Contents

- [Capabilities](#capabilities)
- [Technology](#technology)
- [Requirements](#requirements)
- [Local installation](#local-installation)
- [Multi-store operation](#multi-store-operation)
- [Users and roles](#users-and-roles)
- [Integrations and configuration](#integrations-and-configuration)
- [Queues and scheduled tasks](#queues-and-scheduled-tasks)
- [Health, logs, and backups](#health-logs-and-backups)
- [Development checks](#development-checks)
- [Production deployment](#production-deployment)
- [Project structure](#project-structure)
- [Further documentation](#further-documentation)

## Capabilities

### Dashboard and audits

- Store-scoped operational dashboard with recent audit results and trends.
- Shopify-to-ShipStation order reconciliation with ignored-order support.
- Saved report snapshots, CSV exports, recurring-issue indicators, and re-auditing.
- More than 40 focused checks covering order integrity, fulfillment, carriers, addresses, fraud, refunds, products, inventory, gift cards, tax, and consent.
- Immediate runs in the browser or queued background audit jobs.

The complete categorized list is in [All Tools](docs/tools.md).

### Search and order operations

- Order lookup and multi-order spot checks.
- Shopify/ShipStation comparison and combined order timeline.
- Tracking feed, packing-slip preview, tag search, metafield lookup, and customer history.
- Push a Shopify order to ShipStation and repair an order note.
- Store-scoped print queue, push log, run history, and global search.

### Administration

- Manage stores, encrypted integration credentials, users, roles, and store access.
- Configure Slack, Discord, and per-tool email notification rules.
- Inspect application configuration, API connectivity, and Shopify webhooks.
- Review administrative and operator actions.
- Inspect and download backups, manage banned IPs, and flush application cache.
- View Laravel Health, Horizon, and Pulse dashboards when enabled.

## Technology

- PHP 8.5 and Laravel 13
- Blade, Tailwind CSS 4, and Vite 8
- SQLite by default; Laravel-supported production databases can also be used
- Laravel queues with Redis/Horizon in production
- PHPUnit, Laravel Pint, and Larastan
- Spatie Activity Log, Backup, CSP, and Health
- Optional Google OAuth, Sentry, Slack, Discord, SMTP, Pulse, and Prometheus metrics

## Requirements

- PHP 8.5+ with `curl`, `mbstring`, `pdo_sqlite`, and the standard Laravel extensions
- Composer
- Node.js 24+
- pnpm 11.15.1
- SQLite for the default setup
- Redis for Horizon and the default production queue/cache configuration
- `zip` support for backup archives

## Local installation

Clone the repository and install the application:

```bash
git clone https://github.com/MrGKanev/Shopify-ops.git
cd Shopify-ops
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
pnpm install --frozen-lockfile
pnpm build
php artisan ops:install
```

`ops:install` is interactive. It creates the first administrator, the first store, and the relationship between them. The Shopify token and optional ShipStation credentials entered here are encrypted in the database.

Start the complete development stack:

```bash
composer run dev
```

Open [http://localhost:8000](http://localhost:8000). The development command starts the Laravel server, Vite, log tailing, and Horizon. Redis must be available for Horizon and queued audits.

For UI-only local work without Redis, run the web and frontend processes separately:

```bash
php artisan serve
pnpm dev
```

In local mode the login page also provides development-role login buttons. They are unavailable outside `APP_ENV=local`.

## Multi-store operation

Store integration credentials and operational data—including audit results, saved reports, queue items, and notification rules—belong to a store. A user sees only stores explicitly assigned to that account.

### Add a store

1. Sign in as an administrator.
2. Open **Settings → Stores → Add store**.
3. Enter a display name, unique slug, Shopify store subdomain, Shopify access token, and optional ShipStation credentials.
4. Save the store. The administrator who creates it receives access automatically.

There is intentionally no store-delete action in the interface. Store records contain operational history and should not be removed casually.

### Give another user access

1. Open **Settings → Users**.
2. Create or edit the user.
3. Select one or more entries under **Stores**.
4. Save the user.

Every user must have at least one assigned store.

### Switch the active store

The active store selector appears below **Store** at the top of the left sidebar. It is shown only when the signed-in user has access to more than one store. If it is missing, assign a second store under **Settings → Users**; if the sidebar is collapsed, expand it first.

Selecting another store immediately changes the active store and returns to the dashboard. Subsequent searches, audits, reports, store-specific settings, logs, jobs, and notification rules use that store. The selection is kept in the session. Direct attempts to select an unassigned store are rejected.

## Users and roles

| Role | Access |
| --- | --- |
| `viewer` | Dashboard and read-only lookup tools for assigned stores |
| `operator` | Viewer access plus audits, saved reports, operational actions, and queues |
| `admin` | Full operator access plus Settings, stores, users, health, backups, and security tools |

Administrators manage role and store assignments from **Settings → Users**.

Password login is enabled by default. Google Workspace login can be enabled with `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, and `GOOGLE_ALLOWED_DOMAINS`. Set `GOOGLE_LOGIN_ONLY=true` only after the OAuth flow has been verified.

## Integrations and configuration

Copy `.env.example` and review it before starting the application. The main groups are:

| Area | Configuration |
| --- | --- |
| Application | `APP_ENV`, `APP_KEY`, `APP_URL`, `APP_TIMEZONE`, `APP_DEBUG` |
| Database/session | `DB_*`, `SESSION_*` |
| Queue/cache | `QUEUE_CONNECTION`, `CACHE_STORE`, `CACHE_PREFIX`, `REDIS_*` |
| Email | `MAIL_*` |
| Google login | `GOOGLE_*` |
| Notifications | `SLACK_NOTIFICATION_WEBHOOK_URL`, `DISCORD_NOTIFICATION_WEBHOOK_URL` |
| Backups | `BACKUP_*`, filesystem/S3 variables |
| Observability | `SENTRY_*`, `METRICS_SCRAPE_TOKEN`, `PULSE_*`, `HORIZON_*` |
| Security | `TRUSTED_PROXIES`, `HSTS_ENABLED`, `CSP_*` |

Shopify and ShipStation credentials are managed per store under **Settings → Stores**, not as the normal multi-store source of truth in `.env`.

After configuring a store, use:

- **Settings → API Health** to test Shopify, ShipStation, email, Slack, and Discord delivery.
- **Settings → Config Check** to validate runtime configuration and the active store.
- **Settings → Webhook Health** to inspect Shopify webhook registrations.

The minimum API Health check expects Shopify `read_orders` and `read_fulfillments`. Individual tools may need additional Shopify scopes for their resources, such as fulfillment orders or Shopify Payments disputes. See [Audit Checks](docs/audit-checks.md).

### Notification rules

- Slack and Discord use a deployment-level webhook URL plus store-specific thresholds.
- Email uses the Laravel mail configuration plus store-specific per-tool rules.
- Email rules support `Off`, `Immediate`, and `Digest` modes with thresholds and optional recipient overrides.
- The daily digest uses the store's default alert email when a tool-specific recipient is not set.

### Domain rules

- Order type classification and bundle requirements live in [`config/order-types.php`](config/order-types.php).
- Tag policy rules live in [`config/tag-policy.php`](config/tag-policy.php).
- Audit and search navigation live in [`config/audit-hub.php`](config/audit-hub.php) and [`config/search-hub.php`](config/search-hub.php).
- The notification tool catalog lives in [`config/tool-catalog.php`](config/tool-catalog.php).

## Queues and scheduled tasks

Queued audits require a worker. For a simple non-Horizon environment:

```bash
php artisan queue:work
```

Production uses Redis and Horizon:

```bash
php artisan horizon
```

The scheduler runs health checks and heartbeats, backup jobs, activity-log cleanup, Horizon snapshots, and the daily email digest. Run it locally with:

```bash
php artisan schedule:work
```

Production needs one cron entry:

```cron
* * * * * cd /path/to/shopify-ops && php artisan schedule:run >> /dev/null 2>&1
```

Current scheduled work includes:

- Health checks and worker/scheduler heartbeats every minute
- Database backup daily at 01:15
- Full backup weekly on Sunday at 01:45
- Backup monitoring hourly and cleanup daily
- Activity-log cleanup and health-history pruning daily
- Email report digest daily at 08:00
- Horizon metrics snapshot every five minutes when Redis queues are active

Times use `APP_TIMEZONE`.

## Health, logs, and backups

| Endpoint/page | Purpose |
| --- | --- |
| `/up` | Basic Laravel process health |
| `/ready` | Database, cache, queue, worker, and scheduler readiness |
| `/status` | Application status response |
| `/metrics` | Prometheus metrics; requires `METRICS_SCRAPE_TOKEN` |
| `/admin/health` | Administrator health dashboard |
| `/admin/horizon` | Administrator Redis queue dashboard |
| `/admin/pulse` | Administrator performance dashboard when enabled |

Useful operational commands:

```bash
php artisan config:show app
php artisan schedule:list
php artisan queue:failed
php artisan backup:list
php artisan backup:run
php artisan backup:monitor
```

Backup archives use the disks in `BACKUP_DISKS`; the default local destination is `storage/app/backups`. Administrators can inspect and download available archives from **Settings → Backups**. Store at least one production copy outside the application server.

Application logs are written through Laravel's configured log channel. **Settings → Action Log** shows recorded administrative and operator changes; it is not a replacement for exception logs or Sentry.

## Development checks

Run the same core checks used by CI:

```bash
composer test
composer analyse
vendor/bin/pint --test
composer audit
pnpm build
pnpm audit --audit-level moderate
```

For a focused test:

```bash
php artisan test --compact tests/Feature/ActiveStoreControllerTest.php
```

CI runs on pushes and pull requests to `master` with PHP 8.5, Node.js 24, and pnpm 11.15.1.

## Production deployment

At minimum, production must provide:

- A supported database and persistent application storage
- Redis for cache, queues, Horizon, locks, and health heartbeats
- A long-running Horizon process
- The Laravel scheduler cron entry
- HTTPS with correct `APP_URL`, secure session cookies, trusted proxy configuration, and HSTS after proxy verification
- Built frontend assets and cached Laravel configuration/routes/views
- A tested off-server backup destination

Use the complete [deployment runbook](docs/laravel-deployment-runbook.md) for initial provisioning, supervisor configuration, routine deployments, smoke checks, backup restoration, and fix-forward procedure.

## Project structure

```text
app/Application/       Use-case orchestration for reports, orders, and health
app/Domain/            Analysis and business rules
app/Http/              Controllers, middleware, and validated requests
app/Integrations/      Shopify and ShipStation clients
app/Jobs/              Queued work
app/Models/            Eloquent models and store-scoped records
config/                Laravel, navigation, audit, and policy configuration
database/              Migrations, factories, seeders, and local SQLite file
resources/views/       Blade pages and shared UI components
resources/css/         Tailwind application styles
resources/js/          Browser behavior, including the store switcher
routes/                 Web routes and scheduled commands
tests/                  Feature, unit, architecture, and parity tests
```

Credentials are encrypted through Eloquent casts. Controllers obtain the active store from middleware, while database records and jobs retain their own `store_id` so data does not cross store boundaries.

## Further documentation

- [All tools](docs/tools.md)
- [Audit engine](docs/audit.md)
- [Audit checks](docs/audit-checks.md)
- [Search and lookup](docs/search-lookup.md)
- [Configuration reference](docs/configuration.md)
- [Order type rules](docs/order-types.md)
- [Deployment runbook](docs/laravel-deployment-runbook.md)
- [Laravel migration parity record](docs/parity-verification.md)

## License

Shopify Ops is available under the [MIT License](LICENSE).
