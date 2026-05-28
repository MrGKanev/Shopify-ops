<?= topbar('Audit', 'Run audits, scan for issues and track missing orders') ?>

<?php
$tools = [
    ['page' => 'reports',       'icon' => '📋', 'name' => 'Reports',             'desc' => 'View and download saved audit reports'],
    ['page' => 'run',           'icon' => '▶',  'name' => 'Run Audit',           'desc' => 'Compare Shopify vs ShipStation for any date range'],
    ['page' => 'trends',        'icon' => '📈', 'name' => 'Trends',              'desc' => 'Aggregated stats across all audit reports'],
    ['page' => 'dupes',         'icon' => '🔁', 'name' => 'Duplicate Detector',  'desc' => 'Same customer, same total — placed within 10 minutes'],
    ['page' => 'refunds',       'icon' => '💸', 'name' => 'Refunds Tracker',     'desc' => 'Refunded Shopify orders cross-checked against ShipStation'],
    ['page' => 'addrcheck',     'icon' => '📍', 'name' => 'Address Scanner',     'desc' => 'Paid orders with incomplete or invalid shipping addresses'],
    ['page' => 'emailcheck',    'icon' => '✉',  'name' => 'Email Checker',       'desc' => 'Orders with invalid, disposable or suspicious emails'],
    ['page' => 'orphans',       'icon' => '👻', 'name' => 'Orphan Detector',     'desc' => 'ShipStation orders with no matching Shopify order'],
    ['page' => 'hvorders',      'icon' => '📦', 'name' => 'High-Value No Phone', 'desc' => 'High-value unfulfilled orders missing a shipping phone'],
    ['page' => 'repeatrefunds', 'icon' => '♻',  'name' => 'Repeat Refunds',      'desc' => 'Customers with multiple refunded orders in a date range'],
    ['page' => 'failedship',    'icon' => '🚫', 'name' => 'Voided Shipments',    'desc' => 'ShipStation shipments voided in the selected date range'],
    ['page' => 'addrchanges',   'icon' => '🔀', 'name' => 'Address Changes',     'desc' => 'Orders whose shipping address was edited after placement'],
    ['page' => 'orderedits',    'icon' => '✏️',  'name' => 'Order Edit History',  'desc' => 'Orders with post-placement edits: line items, discounts, notes or custom attributes'],
];
?>
<div class="hub-grid">
  <?php foreach ($tools as $t): ?>
    <a href="?page=<?= esc($t['page']) ?>" class="hub-card">
      <div class="hub-card-icon"><?= $t['icon'] ?></div>
      <div class="hub-card-name"><?= esc($t['name']) ?></div>
      <div class="hub-card-desc"><?= esc($t['desc']) ?></div>
    </a>
  <?php endforeach; ?>
</div>
