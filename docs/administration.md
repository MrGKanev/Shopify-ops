# Administration

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

Password login is enabled by default. Google Workspace login can be enabled — see [Configuration → Google sign-in](configuration.md#google-sign-in).

## Branding and custom links

Administrators can use **Settings → Branding & custom links** to change the site name, upload the shared sidebar/login logo, replace the login artwork, and configure up to five utility links. Links appear at the top right of application pages and can target all users, operators and administrators, or administrators only. Uploaded images use Laravel's public disk, so `php artisan storage:link` is required.

The application version and repository URL come from `package.json` and are shown in the sidebar footer.

## Domain rules

- Order type classification and bundle requirements live in [`config/order-types.php`](../config/order-types.php) — see [Order type rules](order-types.md).
- Tag policy rules live in [`config/tag-policy.php`](../config/tag-policy.php).
- Every report tool is defined once in the tool registry, [`config/reports.php`](../config/reports.php): key, URL slug, label, navigation section, description, controller and required integrations. The report routes, the audit navigation ([`config/audit-hub.php`](../config/audit-hub.php)), the notification tool catalog ([`config/tool-catalog.php`](../config/tool-catalog.php)) and the audit tables in [`docs/tools.md`](tools.md) are derived from it; regenerate the docs with `php artisan docs:tools`.
- Search navigation lives in [`config/search-hub.php`](../config/search-hub.php).

## ShipStation monitoring

Monitoring is **off by default**, including for existing stores after migration. To opt in, an administrator opens **Settings → Stores → Edit store → ShipStation synchronization monitoring** and selects **Enable monitoring**. Saving credentials alone does not enable it.

Requirements:

- Shopify credentials and ShipStation **V1** API key/secret.
- A positive ShipStation store number identifying the store inside the shared SS account.
- A public HTTPS application URL, correctly configured through `APP_URL` and trusted proxies.
- A background queue (`database`, `redis`, `sqs` or `beanstalkd`), its worker, and the Laravel scheduler. `sync` and `null` queues cannot enable monitoring.

The worker registers `SHIP_NOTIFY` and `ORDER_NOTIFY` subscriptions for that SS store. The callback contains a private random token. Events are deduplicated and processed asynchronously; the settings card shows subscription status, failed event count and the last successful catch-up. Setup failures remain visible and are retried by subsequent catch-up runs while monitoring is enabled.

Shipment checks wait 15 minutes after detection for native synchronization. The application verifies the authoritative SS order's store identity before looking up the Shopify order. Missing fulfillment or tracking creates an issue in **Manage → Operational Issues**, showing the shipment, tracking and findings. Partial shipments are compared by line-item identity or an unambiguous SKU and the shipped quantity. Incomplete mapping requires manual review. Voided shipments do not produce missing-fulfillment warnings. Active findings are checked again and resolved when synchronization is confirmed.

**Disable monitoring** immediately refuses callbacks and skips waiting events. Subscription removal runs in the background. If it fails, select **Retry subscription removal** or retry the failed queue job. Store identity and SS credentials cannot change until subscriptions have been removed, including setup interrupted by an API timeout. Re-enabling creates a new callback token, so events from the old activation cannot execute.

A store that has never opted in has no subscription, API polling, monitoring jobs or monitoring issues. The scheduler only discovers enabled stores. Disabling permits the explicitly requested subscription cleanup; it does not continue shipment checks.

This feature records review issues only. It does not mutate orders, create fulfillments, update tracking or buy labels. Review and confirm any correction separately through the remediation tools.

V1 webhooks contain a `resource_url`; retrieving the resource still requires API calls. Only HTTPS `ssapi.shipstation.com` on port 443 and the matching `/orders` or `/shipments` path are accepted. Resource parameters are allowlisted and requests are rebuilt with the configured SS store filter; redirects are refused. Resource retrieval, subscription management and catch-up use the shared client and `IntegrationThrottle`; findings use `RaiseOperationalIssue`.

V2 credentials, events and endpoints are a separate integration and are not enabled here. V1 order/shipment history is not assumed to be available through V2. See the official [V1 webhook documentation](https://help.shipstation.com/hc/en-us/articles/360025856252-ShipStation-Webhooks) and [V1 subscription API](https://www.shipstation.com/docs/api/webhooks/subscribe/).

## Shopify webhook tooling

**Settings → Webhook Health** inspects Shopify subscriptions for the active store, with explicit registration and removal actions. Configure the store's Shopify webhook secret before receiving events. **Settings → Webhook Events** shows received/processed/failed events and provides an explicit retry action. Shopify callbacks verify the HMAC and shop domain, deduplicate deliveries and queue processing. Order events can add post-push change and repeated-address review findings; refund/dispute events enter Operational Issues. These Shopify tools remain separate from the opt-in ShipStation monitor.
