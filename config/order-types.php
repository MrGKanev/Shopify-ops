<?php

return [
    'bundle_check_description' => 'Scans orders containing a Z1 or Z2 grinder and flags any that are missing required accessories (Accent Piece, Funnel Cap, Burr Set).',
    'fallback' => 'Addons',
    'rules' => [
        ['name' => 'Z1', 'match' => 'sku_starts_with', 'value' => 'zerno-z1-', 'exclude_if' => [['match' => 'sku_contains', 'value' => 'warranty'], ['match' => 'title_contains', 'value' => 'warranty']], 'required_items' => [
            ['label' => 'Accent Piece', 'match' => 'title_contains', 'value' => 'accent piece'],
            ['label' => 'Funnel Cap', 'match' => 'title_contains', 'value' => 'funnel cap'],
            ['label' => 'Burr Set', 'match' => 'sku_starts_with', 'value' => ['ssp-', 'burrs-', 'core-', 'ulf-']],
        ]],
        ['name' => 'Z2', 'match' => 'sku_starts_with', 'value' => 'zerno-z2-', 'exclude_if' => [['match' => 'sku_contains', 'value' => 'warranty'], ['match' => 'title_contains', 'value' => 'warranty']], 'required_items' => [
            ['label' => 'Accent Piece', 'match' => 'title_contains', 'value' => 'accent piece'],
            ['label' => 'Funnel Cap', 'match' => 'title_contains', 'value' => 'funnel cap'],
            ['label' => 'Burr Set', 'match' => 'sku_starts_with', 'value' => ['ssp-', 'burrs-', 'core-', 'ulf-']],
        ]],
    ],
];
