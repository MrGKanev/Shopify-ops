<?= topbar('Search & Lookup', 'Find specific orders, customers, tags and tracking info') ?>

<?php
$tools = [
    ['page' => 'spotcheck',  'icon' => '🔎', 'name' => 'Spot-check',        'desc' => 'Live lookup of specific order numbers in ShipStation and Shopify'],
    ['page' => 'customer',   'icon' => '👤', 'name' => 'Customer Lookup',   'desc' => 'Full order history for a customer by email address'],
    ['page' => 'tagsearch',  'icon' => '🔖', 'name' => 'Tag Search',        'desc' => 'Find all orders that carry a specific Shopify tag'],
    ['page' => 'tagaudit',   'icon' => '🏷',  'name' => 'Tag Audit',         'desc' => 'All unique tags on orders — with frequency and last-seen date'],
    ['page' => 'metafields', 'icon' => '🗂',  'name' => 'Metafields',        'desc' => 'Browse metafield definitions and search orders by value'],
    ['page' => 'tracking',   'icon' => '🚚', 'name' => 'Tracking Feed',     'desc' => 'Shipment tracking info for orders via ShipStation'],
    ['page' => 'compare',    'icon' => '⚖',  'name' => 'Order Compare',     'desc' => 'Two orders side by side with differences highlighted'],
    ['page' => 'timeline',   'icon' => '📅', 'name' => 'Order Timeline',    'desc' => 'Full chronological history of a single order: Shopify events + ShipStation shipments'],
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
