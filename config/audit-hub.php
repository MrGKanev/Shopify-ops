<?php

// Mirrors legacy's ToolRegistry::HUBS['audit']['sections'] (src/ToolRegistry.php)
// so every audit/report tool is discoverable from the sidebar, not just the
// 12 links that were hand-picked for the old context-links list.

return [
    'Core Audit' => [
        ['label' => 'Saved Reports', 'route' => 'saved-reports.index'],
        ['label' => 'Run Audit', 'route' => 'reports.run-audit'],
        ['label' => 'Trends', 'route' => 'report-trends.index'],
    ],
    'Order Issues' => [
        ['label' => 'Duplicate Detector', 'route' => 'reports.duplicate-orders'],
        ['label' => 'Refunds Tracker', 'route' => 'reports.refund-tracker'],
        ['label' => 'Repeat Refunds', 'route' => 'reports.repeat-refunds'],
        ['label' => 'Return / RMA Tracker', 'route' => 'reports.return-rma'],
        ['label' => 'Returned Items Report', 'route' => 'reports.returned-items'],
        ['label' => 'Orphan Detector', 'route' => 'reports.orphan-orders'],
        ['label' => 'Active SS Conflicts', 'route' => 'reports.active-shipstation-conflicts'],
        ['label' => 'SS Shipped / Shopify Unfulfilled', 'route' => 'reports.shipped-unfulfilled'],
        ['label' => 'Order Edit History', 'route' => 'reports.order-edits'],
        ['label' => 'Note Flags', 'route' => 'reports.note-flags'],
    ],
    'Address & Contact' => [
        ['label' => 'Address Scanner', 'route' => 'reports.address-check'],
        ['label' => 'Email Checker', 'route' => 'reports.email-check'],
        ['label' => 'High-Value No Phone', 'route' => 'reports.high-value-no-phone'],
        ['label' => 'Address Changes', 'route' => 'reports.address-changes'],
        ['label' => 'Post-Ship Address Change', 'route' => 'reports.post-ship-address-changes'],
        ['label' => 'Duplicate Shipping Addresses', 'route' => 'reports.duplicate-addresses'],
    ],
    'Fulfillment' => [
        ['label' => 'Voided Shipments', 'route' => 'reports.voided-shipments'],
        ['label' => 'Fulfillment SLA Breaches', 'route' => 'reports.fulfillment-sla'],
        ['label' => 'Bundle Check', 'route' => 'reports.bundle-check'],
        ['label' => 'Partial Fulfillment Stalls', 'route' => 'reports.partial-fulfillment'],
        ['label' => 'On-Hold Stall', 'route' => 'reports.on-hold-stall'],
        ['label' => 'Fulfilled Without Tracking', 'route' => 'reports.no-tracking'],
        ['label' => 'Shipment Aging', 'route' => 'reports.shipment-aging'],
        ['label' => 'Shipped Item Mismatch', 'route' => 'reports.item-mismatch'],
        ['label' => 'Fulfilled Items Report', 'route' => 'reports.fulfilled-items'],
    ],
    'Carrier Analytics' => [
        ['label' => 'Carrier Performance', 'route' => 'reports.carrier-performance'],
        ['label' => 'Shipping Margin Erosion', 'route' => 'reports.shipping-margin'],
    ],
    'Products & Inventory' => [
        ['label' => 'Product Completeness', 'route' => 'reports.product-completeness'],
        ['label' => 'SKU Duplicates', 'route' => 'reports.sku-duplicates'],
        ['label' => 'Inventory Oversell Risk', 'route' => 'reports.inventory-oversell'],
        ['label' => 'Inventory Aging', 'route' => 'reports.inventory-aging'],
        ['label' => 'Inventory Forecast', 'route' => 'reports.inventory-forecast'],
        ['label' => 'Zombie Products', 'route' => 'reports.zombie-products'],
        ['label' => 'Catalog Quality', 'route' => 'reports.catalog-quality'],
    ],
    'Gift Cards' => [
        ['label' => 'Gift Cards', 'route' => 'reports.gift-cards'],
    ],
    'Fraud & Compliance' => [
        ['label' => 'Billing ≠ Shipping Country', 'route' => 'reports.country-mismatch'],
        ['label' => 'Discount Abuse', 'route' => 'reports.discount-abuse'],
        ['label' => 'Tag Policy Audit', 'route' => 'reports.tag-policy'],
        ['label' => 'Tax Audit', 'route' => 'reports.tax-audit'],
        ['label' => 'Marketing Consent Audit', 'route' => 'reports.consent-audit'],
        ['label' => 'Fraud Risk Report', 'route' => 'reports.fraud-risk'],
        ['label' => 'Same IP, Different Emails', 'route' => 'reports.same-ip'],
        ['label' => 'Chargebacks / Disputes', 'route' => 'reports.disputes'],
    ],
];
