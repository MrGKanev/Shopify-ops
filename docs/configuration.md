# Configuration

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `SHOPIFY_STORE` | ✅ | Subdomain of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify Admin API access token |
| `SS_API_KEY` | - | ShipStation → Settings → API (required for audit/push features) |
| `SS_API_SECRET` | - | Same page |
| `GOOGLE_CLIENT_ID` | - | OAuth 2.0 Web application client ID from Google Cloud. Required to enable Google sign-in. |
| `GOOGLE_CLIENT_SECRET` | - | OAuth client secret. Required to enable Google sign-in. |
| `GOOGLE_REDIRECT_URI` | - | Callback URL registered in Google Cloud. Defaults to `${APP_URL}/auth/google/callback`. |
| `GOOGLE_ALLOWED_DOMAINS` | - | Comma-separated Google Workspace domains allowed to sign in. |
| `GOOGLE_LOGIN_ONLY` | - | Set to `true` to disable password login. |
| `TRUSTED_PROXIES` | - | Comma-separated proxy IPs/CIDRs whose forwarded HTTPS and client-IP headers may be trusted. Leave empty when not behind a proxy. |
| `HSTS_ENABLED` | - | Set to `true` once HTTPS is confirmed working end-to-end. |
| `CSP_ENABLED` / `CSP_REPORT_ONLY` | - | Content-Security-Policy enforcement (spatie/laravel-csp). Ships report-only by default. |
| `SESSION_LIFETIME` | - | Idle-session timeout in minutes (default: `120`). |
| `QUEUE_CONNECTION` | - | `redis` in production (Horizon-managed); `database`/`sync` work for local dev. |
| `CACHE_STORE` / `CACHE_PREFIX` | - | Redis cache store. `CACHE_PREFIX` must be unique per deployment when Redis is shared. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | - | Standard Laravel mail transport. Required for any Email Rules notification. |
| `SLACK_NOTIFICATION_WEBHOOK_URL` | - | Slack Incoming Webhook URL. Thresholds are configured in **Settings → Slack Rules**, not `.env`. |
| `DISCORD_NOTIFICATION_WEBHOOK_URL` | - | Discord webhook URL. Thresholds are configured in **Settings → Discord Rules**. |
| `METRICS_SCRAPE_TOKEN` | - | Bearer token required by `GET /metrics`. Leave empty to keep the endpoint disabled (404). |
| `ACTIVITYLOG_ENABLED` | - | Enables the operator Action Log (spatie/laravel-activitylog). |
| `SENTRY_LARAVEL_DSN` | - | Error tracking. Leave empty to disable. |
| `BACKUP_DISKS`, `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_NOTIFICATIONS_ENABLED` | - | spatie/laravel-backup configuration; see **Settings → Backups**. |
| `HORIZON_PATH` | - | URL path for the Horizon dashboard (Redis queue only). |
| `PULSE_ENABLED` / `PULSE_PATH` | - | Application performance monitoring dashboard. |

See `.env.example` for the full list, including AWS/S3, database, and broadcast settings.

---

## Caching

Redis (`CACHE_STORE`) is used only for locks, unique-job deduplication, and health-check heartbeats — **Shopify/ShipStation API reads and report data are always fetched live, never cached.** This is a deliberate departure from the legacy PHP app, which cached API responses under `cache/` with per-endpoint TTLs; the Laravel rewrite trades that for always-current data at the cost of more API calls. See `docs/parity-verification.md` for the audit that confirmed this.

## Background jobs

Queued audits run on the Laravel queue (`AuditJob`/`RunAuditJob`), tracked per-store in **Job Queue**. Start a worker:

```bash
php artisan queue:work
```

Or run Horizon if `QUEUE_CONNECTION=redis` (dashboard at the `HORIZON_PATH` route). Scheduled tasks (daily email digest, health checks, backups, activity-log cleanup — see `routes/console.php`) run via:

```bash
php artisan schedule:work
```

In production, schedule that command (or `schedule:run` on a cron minute-tick) with your process supervisor.

## Slack rules

Set `SLACK_NOTIFICATION_WEBHOOK_URL` in `.env`, then configure thresholds in **Settings → Slack Rules**.

- Audit notifications can require a minimum missing-order count.
- All-clear audit notifications can be disabled.
- Scan notifications are optional and default to off to avoid noisy channels.
- `@mention` prefixes are sanitized on both save and read (defense-in-depth against a hand-edited value).

## Discord rules

Set `DISCORD_NOTIFICATION_WEBHOOK_URL` in `.env`, then configure thresholds in **Settings → Discord Rules**. Same shape as Slack rules, minus mentions (Discord has no equivalent concept here).

## Email rules

Set `MAIL_*` in `.env`, then configure each check individually in **Settings → Email Rules**. Every audit/scan check gets its own row:

- **Off** (default) - never emails.
- **Immediate** - emails right after that check's own run, once its row/missing count clears the threshold.
- **Digest** - held for a once-daily rollup email (`reports:email-digest`, scheduled at 08:00 — see `routes/console.php`).

Each check can override the recipient; leave it blank to fall back to the store's **default alert email** (also set on the Email Rules page) — one address can cover every tool that doesn't need its own override.

## Tag policy rules

`Tag Policy Audit` is driven by [`config/tag-policy.php`](../config/tag-policy.php):

```php
return [
    'required' => [
        ['name' => 'Express orders need priority review', 'when' => ['express'], 'must_have' => ['priority-review']],
    ],
    'forbidden' => [
        ['name' => 'Wholesale cannot be fraud review', 'tags' => ['wholesale', 'fraud-review']],
    ],
];
```

## Order type rules

`Bundle Check` and the missing-by-type dashboard breakdown are driven by [`config/order-types.php`](../config/order-types.php) — see [order-types.md](order-types.md).

---

## Security

- Google sign-in uses Laravel Socialite's OAuth flow.
- Domain access is checked server-side against Google's verified Workspace `hd` claim.
- Idle sessions expire after `SESSION_LIFETIME` minutes (default: 120).
- CSP, HSTS, and other security headers are configured via `.env` (`CSP_ENABLED`, `HSTS_ENABLED`) and applied by spatie/laravel-csp and Laravel's own middleware.
- Forwarded protocol/client-IP headers are used only when the direct peer matches `TRUSTED_PROXIES`.
- Password login remains available unless `GOOGLE_LOGIN_ONLY=true`.
- 3 failed login attempts per IP triggers a 1-week lockout, manageable from **Settings → Banned IPs**.
- `GET /metrics` requires `METRICS_SCRAPE_TOKEN`; leave it empty to keep the endpoint disabled.
- All Blade output is escaped by default.

## Google sign-in

1. In Google Cloud Console, create an **OAuth client ID** with application type **Web application**.
2. Add the exact `GOOGLE_REDIRECT_URI` to **Authorized redirect URIs** (defaults to `${APP_URL}/auth/google/callback`).
3. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, and `GOOGLE_ALLOWED_DOMAINS` in `.env`.
4. Optionally set `GOOGLE_LOGIN_ONLY=true` to disable password login.

Accounts authenticated by Google but outside `GOOGLE_ALLOWED_DOMAINS` are redirected to an access-denied page.

---

## Creating a Shopify access token

1. Shopify Admin → **Settings → Apps and sales channels → Develop apps**
2. **Create an app**, then **Configuration → Admin API integration → Edit**
3. Enable scopes: `read_orders`, `read_fulfillments`, `read_metaobjects`
4. **Save** → **API credentials → Install app**
5. Copy the **Admin API access token** - shown only once
