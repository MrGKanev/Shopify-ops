<?= topbar('Audit', 'Run audits, scan for issues and track missing orders') ?>

<?php
$groups = [
    'Core Audit' => [
        ['page' => 'reports',  'icon' => '📋', 'name' => 'Reports',    'desc' => 'View and download saved audit reports'],
        ['page' => 'run',      'icon' => '▶',  'name' => 'Run Audit',  'desc' => 'Compare Shopify vs ShipStation for any date range'],
        ['page' => 'trends',   'icon' => '📈', 'name' => 'Trends',     'desc' => 'Aggregated stats across all audit reports'],
    ],
    'Order Issues' => [
        ['page' => 'dupes',         'icon' => '🔁', 'name' => 'Duplicate Detector',          'desc' => 'Same customer, same total - placed within 10 minutes'],
        ['page' => 'refunds',       'icon' => '💸', 'name' => 'Refunds Tracker',             'desc' => 'Refunded Shopify orders cross-checked against ShipStation'],
        ['page' => 'repeatrefunds', 'icon' => '♻',  'name' => 'Repeat Refunds',              'desc' => 'Customers with multiple refunded orders in a date range'],
        ['page' => 'orphans',       'icon' => '👻', 'name' => 'Orphan Detector',             'desc' => 'ShipStation orders with no matching Shopify order'],
        ['page' => 'ssshipped',     'icon' => '🔄', 'name' => 'SS Shipped / Shopify Unful.', 'desc' => 'ShipStation shipped orders that Shopify still shows as unfulfilled (sync failure)'],
        ['page' => 'orderedits',    'icon' => '✏️',  'name' => 'Order Edit History',          'desc' => 'Orders with post-placement edits: line items, discounts, notes or custom attributes'],
        ['page' => 'noteflags',     'icon' => '🚩', 'name' => 'Note Flags',                  'desc' => 'Paid unfulfilled orders with flagged keywords in the order note'],
    ],
    'Address & Contact' => [
        ['page' => 'addrcheck',    'icon' => '📍', 'name' => 'Address Scanner',           'desc' => 'Paid orders with incomplete or invalid shipping addresses'],
        ['page' => 'emailcheck',   'icon' => '✉',  'name' => 'Email Checker',             'desc' => 'Orders with invalid, disposable or suspicious emails'],
        ['page' => 'hvorders',     'icon' => '📦', 'name' => 'High-Value No Phone',       'desc' => 'High-value unfulfilled orders missing a shipping phone'],
        ['page' => 'addrchanges',  'icon' => '🔀', 'name' => 'Address Changes',           'desc' => 'Orders whose shipping address was edited after placement'],
        ['page' => 'postshipaddr', 'icon' => '📮', 'name' => 'Post-Ship Address Change',  'desc' => 'Address edited AFTER the order was already fulfilled — package already in transit'],
        ['page' => 'addrdupes',    'icon' => '👥', 'name' => 'Duplicate Shipping Addrs.', 'desc' => 'Different customer emails shipping to the exact same address'],
    ],
    'Fulfillment' => [
        ['page' => 'failedship',    'icon' => '🚫', 'name' => 'Voided Shipments',           'desc' => 'ShipStation shipments voided in the selected date range'],
        ['page' => 'bundlecheck',   'icon' => '🧩', 'name' => 'Bundle Check',               'desc' => 'Bundled orders missing required companion items (Addon items)'],
        ['page' => 'partialfulfill','icon' => '⏳', 'name' => 'Partial Fulfillment Stalls',  'desc' => 'Open orders partially shipped with unfulfilled items stalled for N+ days'],
        ['page' => 'onholdstall',   'icon' => '⏸', 'name' => 'On-Hold Stall',              'desc' => 'Fulfillment orders sitting on hold — sorted by how long the order has been waiting'],
        ['page' => 'notracking',    'icon' => '📪', 'name' => 'Fulfilled Without Tracking', 'desc' => 'Fulfilled orders with no tracking number after a configurable grace period'],
    ],
    'Products & Inventory' => [
        ['page' => 'productcheck',     'icon' => '🖼',  'name' => 'Product Completeness',   'desc' => 'Active products missing images, descriptions, or variant SKUs'],
        ['page' => 'skudupes',         'icon' => '🔑',  'name' => 'SKU Duplicates',          'desc' => 'Variants sharing the same SKU across your product catalog'],
        ['page' => 'inventoryoversell','icon' => '📉',  'name' => 'Inventory Oversell Risk', 'desc' => 'SKUs where ShipStation awaiting qty exceeds available Shopify stock'],
        ['page' => 'zombieproducts',   'icon' => '🧟',  'name' => 'Zombie Products',         'desc' => 'Active products with no variants or all tracked variants permanently out of stock'],
    ],
    'Fraud & Compliance' => [
        ['page' => 'countrymismatch', 'icon' => '🌍', 'name' => 'Billing ≠ Shipping Country', 'desc' => 'Paid orders where billing and shipping countries differ - a documented fraud signal'],
    ],
];
?>

<?php foreach ($groups as $label => $tools): ?>
<div class="hub-section">
  <div class="hub-section-title"><?= esc($label) ?></div>
  <div class="hub-grid">
    <?php foreach ($tools as $t): ?>
      <a href="?page=<?= esc($t['page']) ?>" class="hub-card">
        <div class="hub-card-icon"><?= $t['icon'] ?></div>
        <div class="hub-card-name"><?= esc($t['name']) ?></div>
        <div class="hub-card-desc"><?= esc($t['desc']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
