# Configuration

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `SHOPIFY_STORE` | ✅ | Subdomain of `yourstore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | ✅ | Shopify Admin API access token |
| `SS_API_KEY` | - | ShipStation → Settings → API (required for audit/push features) |
| `SS_API_SECRET` | - | Same page |
| `WEB_PASSWORD` | ✅ | Dashboard login password. Plain text is supported for compatibility; a PHP `password_hash()` value is also accepted. |
| `WEB_USERNAME` | - | Login username (default: `admin`) |
| `CACHE_TTL` | - | Cache duration in seconds (default: `82800` = 23 h). Set to `0` to disable. |
| `APP_TITLE` | - | Label shown in browser tab and sidebar as `{APP_TITLE} - Shopify OPS` (default: `Shopify OPS`) |
| `APP_LOGO` | - | URL to an image that replaces the brand text |
| `APP_STORE_NUMBER` | - | Store number - shown as subtitle on login and in the browser tab |
| `SLACK_WEBHOOK_URL` | - | Slack Incoming Webhook URL. When set, completed audits send a concise summary to Slack. |

---

## Caching

API responses are cached under `cache/` as JSON files keyed by platform and date range. Default TTL: 23 hours (`CACHE_TTL=82800`). Repeated runs within the same day reuse the cache automatically.

To force a fresh fetch: **Clear all cache** in the Run Audit page, or set `CACHE_TTL=0` in `.env`.

## Tag policy rules

`Tag Policy Audit` is enabled by creating `tag_policy.json` from `tag_policy.example.json`.

```json
{
  "required": [
    { "name": "Express orders need priority review", "when": ["express"], "must_have": ["priority-review"] }
  ],
  "forbidden": [
    { "name": "Wholesale cannot be fraud review", "tags": ["wholesale", "fraud-review"] }
  ]
}
```

---

## Security

- Username/password authentication stored in `.env`
- 3 failed login attempts per IP triggers a 1-week lockout (manageable from Settings)
- All user-supplied values escaped with `htmlspecialchars`
- Protect data directories from direct web access:

```apache
<DirectoryMatch "^/var/www/shopify-ops/(reports|cache|data|logs)/">
    Require all denied
</DirectoryMatch>
```

---

## Creating a Shopify access token

1. Shopify Admin → **Settings → Apps and sales channels → Develop apps**
2. **Create an app**, then **Configuration → Admin API integration → Edit**
3. Enable scopes: `read_orders`, `read_fulfillments`, `read_metaobjects`
4. **Save** → **API credentials → Install app**
5. Copy the **Admin API access token** - shown only once
