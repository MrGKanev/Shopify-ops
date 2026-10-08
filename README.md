# Shopify Ops

Shopify Ops is a self-hosted operations console for Shopify and ShipStation. It combines live order lookup, operational audits, saved reports, queue management, notifications, health checks, backups, and multi-store access in one Laravel application.

Shopify is the primary integration. ShipStation is optional for Shopify-only tools, but required for cross-platform audits, shipment checks, packing slips, tracking, and pushing orders.

## Capabilities

- **Dashboard and audits** — store-scoped dashboard, Shopify/ShipStation reconciliation, saved reports, CSV exports, and 40+ focused checks covering order integrity, fulfillment, carriers, addresses, fraud, refunds, products, inventory, gift cards, tax, and consent. See [All tools](docs/tools.md).
- **Search and order operations** — order lookup, spot checks, tracking, packing slips, tag/metafield search, customer history, and pushing a Shopify order to ShipStation. See [Search and lookup](docs/search-lookup.md).
- **Controlled order remediation** — preview changes, explicitly confirm an action, and recheck permissions and external state before execution. Includes ShipStation order synchronization, import refresh, Shopify fulfillment/tracking, holds, tags and notes. See [Order remediation](docs/search-lookup.md#order-remediation).
- **Optional ShipStation monitoring** — off by default for every store. An administrator can enable V1 shipment/order webhooks and missed-shipment catch-up; after a 15-minute grace period, missing Shopify fulfillment or tracking becomes an Operational Issue. Partial shipments are checked by item and quantity. No automatic order changes. See [ShipStation monitoring](docs/administration.md#shipstation-monitoring).
- **Operational triage** — issues with owners, priorities, deadlines and resolution history; order changes after a push, shared shipping-address warnings, return/RMA deadlines and operational digests.
- **Cost and shipment decisions** — contribution margin with explicit missing-cost/currency coverage, customs readiness with source conflicts, and rate shopping with captured decisions distinguished from current-rate simulations. Applying a selected service requires confirmation and checks the external result.
- **English and Bulgarian interface** — localized application screens, reports and operational messages.
- **Administration** — stores, users, roles, notification rules, config/health checks, Shopify webhook subscriptions and event retries, action log, backups, and Laravel Health/Horizon/Pulse dashboards. See [Administration](docs/administration.md).

## Quick start

```bash
git clone https://github.com/MrGKanev/Shopify-ops.git
cd Shopify-ops
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan storage:link
pnpm install --frozen-lockfile
pnpm build
php artisan ops:install
composer run dev
```

Open [http://localhost:8000](http://localhost:8000). `ops:install` is interactive and creates the first administrator and store. Full requirements, technology stack, and setup details are in [Installation](docs/installation.md).

## Documentation

See the [documentation index](docs/README.md) for everything else: administration, configuration, operations, deployment, and architecture.

## License

Shopify Ops is available under the [MIT License](LICENSE).
