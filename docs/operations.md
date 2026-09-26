# Operations

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
php artisan backup:verify-latest
php artisan backup:restore
```

Backup archives use the disks in `BACKUP_DISKS`; the default local destination is `storage/app/backups`. Administrators can inspect and download available archives from **Settings → Backups**. Store at least one production copy outside the application server.

### Backups {#backups}

From **Settings → Backups**, an administrator can verify an archive and start a
restore by entering its exact filename. The app rechecks its checksum, creates
and verifies a full safety backup, then briefly enters maintenance mode while
restoring. The operation status is stored outside the database under
`storage/app/backup-operations`; reload the page to see the latest status.

The `backup:restore` command is also available in CLI. It restores the latest
archive by default, or a specified path relative to the `backups` disk, and
prompts before replacing the database. It supports SQLite and MySQL/MariaDB
(`mysql` client must be on `PATH` for the latter). Files included in the archive
replace matching paths; files absent from the archive are left in place.

Application logs are written through Laravel's configured log channel. **Settings → Action Log** shows recorded administrative and operator changes; it is not a replacement for exception logs or Sentry.

## Development checks {#local-test-build}

Run the exact checks CI runs, as a single command:

```bash
composer ci
```

This chains `composer audit`, `composer test`, `composer analyse`,
`vendor/bin/pint --test`, `pnpm install --frozen-lockfile`, `pnpm build`, and
`pnpm audit --audit-level moderate` — the same steps as `.github/workflows/ci.yml`.
Run it before considering a change release-ready; a passing local run is
current evidence, historical test counts in old docs are not.

Individual checks:

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

CI runs on pushes and pull requests to `master` with PHP 8.5, Node.js 24, and pnpm 12.5.1.

## Production deployment

At minimum, production must provide:

- A supported database and persistent application storage
- Redis for cache, queues, Horizon, locks, and health heartbeats
- A long-running Horizon process
- The Laravel scheduler cron entry
- HTTPS with correct `APP_URL`, secure session cookies, and HSTS (set `TRUSTED_PROXIES` only if a reverse proxy/load balancer sits in front of the app)
- Built frontend assets and cached Laravel configuration/routes/views
- A tested off-server backup destination

These are the production requirements for the current app; use your hosting provider's process manager and deployment workflow to configure them.
