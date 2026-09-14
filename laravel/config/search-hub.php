<?php

// Mirrors legacy's ToolRegistry::HUBS['search']['sections'] (src/ToolRegistry.php).

return [
    'Orders' => [
        ['label' => 'Spot-check', 'route' => 'orders.spot-check'],
        ['label' => 'Order Compare', 'route' => 'orders.compare'],
        ['label' => 'Order Timeline', 'route' => 'orders.timeline'],
    ],
    'Customers & Tags' => [
        ['label' => 'Customer Lookup', 'route' => 'customers.lookup'],
        ['label' => 'Customer LTV', 'route' => 'reports.customer-ltv'],
        ['label' => 'Tag Search', 'route' => 'orders.tag-search'],
        ['label' => 'Tag Audit', 'route' => 'reports.tag-audit'],
    ],
    'Metadata' => [
        ['label' => 'Metafields', 'route' => 'metafields.index'],
    ],
    'Shipping' => [
        ['label' => 'Tracking Feed', 'route' => 'orders.tracking'],
        ['label' => 'Packing Slip Preview', 'route' => 'orders.packing-slip'],
    ],
];
