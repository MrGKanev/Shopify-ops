# Order Type Classification

Orders are automatically classified into named types based on line items. The label appears as a coloured chip in the missing-orders table and in exported CSV reports.

## Setup

```bash
cp order_types.example.json order_types.json
```

## Configuration

```json
{
  "fallback": "Accessory",
  "rules": [
    { "name": "Pro",    "match": "sku_starts_with", "value": "widget-pro-" },
    { "name": "Bundle", "match": "title_contains",  "value": "starter kit" },
    { "name": "OEM",    "match": "vendor_is",        "value": "Acme Corp" }
  ]
}
```

Rules are evaluated top-to-bottom. The first match wins. If no rule matches, the order is classified as the `fallback` value.

## Match types

| Match type | Behaviour |
|---|---|
| `sku_starts_with` | SKU starts with the given string (case-insensitive) |
| `sku_contains` | SKU contains the given string (case-insensitive) |
| `sku_not_starts_with` | SKU does **not** start with any of the given prefixes |
| `title_contains` | Product title contains the given string (case-insensitive) |
| `vendor_is` | Product vendor exactly matches the given string (case-insensitive) |

## Required items check

Certain order types can be configured to require specific line items. If an order of that type is missing a required item, it is flagged in the audit output.

```json
{
  "fallback": "Accessory",
  "order_types": {
    "Z1": { "required_items": ["Accent Piece", "Funnel Cap", "Burr Set"] },
    "Z2": { "required_items": ["Accent Piece", "Funnel Cap", "Burr Set"] }
  },
  "rules": [
    { "name": "Z1", "match": "sku_starts_with", "value": "z1-" },
    { "name": "Z2", "match": "sku_starts_with", "value": "z2-" }
  ]
}
```
