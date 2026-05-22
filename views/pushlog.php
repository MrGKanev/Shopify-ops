<?= topbar('Push Log', 'History of orders pushed to ShipStation from this dashboard') ?>

<div class="table-wrap">
  <div class="table-header">
    <h2>Push history</h2>
    <span><?= count($pushLog) ?> push<?= count($pushLog) !== 1 ? 'es' : '' ?></span>
  </div>

  <?php if (empty($pushLog)): ?>
    <div class="empty">
      <div class="icon">📋</div>
      <h3>No pushes yet</h3>
      <p>Orders pushed to ShipStation via the dashboard will appear here.</p>
    </div>
  <?php else: ?>
    <div class="search-wrap">
      <input class="js-search" data-target="pushlog-tbl"
             placeholder="Filter by order #, Shopify ID…" type="search">
    </div>
    <table>
      <thead>
        <tr>
          <th>Order #</th>
          <th>Shopify ID</th>
          <th>SS Order ID</th>
          <th>Pushed at</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="pushlog-tbl">
        <?php foreach ($pushLog as $entry):
          $orderNum  = $entry['order_number'] ?? '-';
          $shopifyId = $entry['shopify_id']   ?? '';
          $ssId      = $entry['ss_order_id']  ?? '-';
          $pushedAt  = $entry['pushed_at']    ?? '-';
          $adminUrl  = $shopifyId
            ? 'https://' . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com") . '/admin/orders/' . esc($shopifyId)
            : null;
        ?>
        <tr>
          <td class="order-num"><?= esc($orderNum) ?></td>
          <td class="font-mono text-[.8rem] text-muted">
            <?php if ($adminUrl): ?>
              <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($shopifyId) ?></a>
            <?php else: ?>
              <?= esc($shopifyId) ?>
            <?php endif; ?>
          </td>
          <td class="font-mono text-[.8rem] text-muted"><?= esc($ssId) ?></td>

          <td class="text-sm"><?= esc($pushedAt) ?></td>
          <td>
            <a class="ignore-btn"
               href="https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch=<?= urlencode(ltrim($orderNum, '#')) ?>"
               target="_blank" rel="noopener">View in SS</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
