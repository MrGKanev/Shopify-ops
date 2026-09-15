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
- Audit and search navigation live in [`config/audit-hub.php`](../config/audit-hub.php) and [`config/search-hub.php`](../config/search-hub.php).
- The notification tool catalog lives in [`config/tool-catalog.php`](../config/tool-catalog.php).
