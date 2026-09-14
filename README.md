# Shopify Ops

A self-hosted Shopify operations toolkit built on Laravel. Audits and surfaces
Shopify order issues, provides search and lookup tools, and syncs with
ShipStation for order matching and push.

> **Shopify is the only required integration.** Most pages work with a
> Shopify access token alone. ShipStation credentials are optional — needed
> only for the audit engine, push log, and order matching features.

---

## Tools

- **Audit** — Run Audit, Saved Reports, Trends, duplicate/refund/address/email/fraud/product/inventory checks
- **Search & Lookup** — Spot-check, Order Timeline, Order Compare, Metafields, Tag Search, Customer Lookup, Tracking, Packing Slip
- **Manage** — Ignored Orders, Push Log, Run History, Job Queue, Print Queue, Settings

---

## Requirements

- PHP 8.5+ with the `curl`, `mbstring`, and `pdo_sqlite` extensions
- Composer
- Node.js 24+ and pnpm 11.15.1
- SQLite (or another database supported by Laravel)

---

## Setup

```bash
git clone https://github.com/MrGKanev/Shopify-ops.git
cd Shopify-ops/
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan ops:install
pnpm install
pnpm build
```

Edit `.env` with your Shopify/ShipStation credentials, then start the app:

```bash
php artisan serve
```

The framework health endpoint is available at `/up`.

### Background jobs

Queued audits are processed by the Laravel queue worker:

```bash
php artisan queue:work
```

Scheduled tasks (daily digests, health checks, backups) run via the Laravel scheduler:

```bash
php artisan schedule:work
```

---

## Checks

```bash
composer test
vendor/bin/pint --format agent
composer analyse
composer audit
```

---

## Further reading

- [Parity-verification history](docs/parity-verification.md) — the independent audit that verified every tool against the retired legacy PHP implementation before cutover
- [Deployment runbook](docs/laravel-deployment-runbook.md)
- [Order type classification — rules, JSON config, required items](docs/order-types.md)
