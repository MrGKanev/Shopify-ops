# Order Type Classification

Orders are automatically classified into named types based on line items. The label appears as a coloured chip in the missing-orders table, in exported CSV reports, and in the Dashboard's missing-by-type breakdown.

## Configuration

Rules live in [`config/order-types.php`](../config/order-types.php):

```php
return [
    'fallback' => 'Accessory',
    'rules' => [
        ['name' => 'Pro', 'match' => 'sku_starts_with', 'value' => 'widget-pro-'],
        ['name' => 'Bundle', 'match' => 'title_contains', 'value' => 'starter kit'],
        ['name' => 'OEM', 'match' => 'vendor_is', 'value' => 'Acme Corp'],
    ],
];
```

Rules are evaluated top-to-bottom against each order's line items (`App\Domain\Orders\OrderTypeClassifier::classify()`). An order can match more than one rule — matched names are joined with ` + `. If no rule matches, the order is classified as the `fallback` value.

## Match types

| Match type | Behaviour |
|---|---|
| `sku_starts_with` | SKU starts with the given string (case-insensitive) |
| `sku_contains` | SKU contains the given string (case-insensitive) |
| `sku_not_starts_with` | SKU does **not** start with any of the given prefixes |
| `title_contains` | Product title contains the given string (case-insensitive) |
| `vendor_is` | Product vendor exactly matches the given string (case-insensitive) |

`value` may be a string or an array of strings (any-match).

## Required items check

A rule can require specific companion line items — used by **Bundle Check** to flag orders missing an accessory. `exclude_if` skips the required-items check entirely when the order also matches one of its own listed conditions (for example, a warranty-only line item).

```php
return [
    'fallback' => 'Addons',
    'rules' => [
        [
            'name' => 'Z1',
            'match' => 'sku_starts_with',
            'value' => 'zerno-z1-',
            'exclude_if' => [
                ['match' => 'sku_contains', 'value' => 'warranty'],
                ['match' => 'title_contains', 'value' => 'warranty'],
            ],
            'required_items' => [
                ['label' => 'Accent Piece', 'match' => 'title_contains', 'value' => 'accent piece'],
                ['label' => 'Funnel Cap', 'match' => 'title_contains', 'value' => 'funnel cap'],
                ['label' => 'Burr Set', 'match' => 'sku_starts_with', 'value' => ['ssp-', 'burrs-', 'core-', 'ulf-']],
            ],
        ],
    ],
];
```

An order that matches `Z1`'s trigger condition but is missing any of its `required_items` is flagged by Bundle Check, listing exactly which required item(s) were not found.
