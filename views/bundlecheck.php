<?php
$_bcRules     = array_filter($bcConfig['rules'] ?? [], fn($r) => !empty($r['required_items']));
$_bcTypeNames = implode(', ', array_column($_bcRules, 'name'));
$_bcDesc      = $bcConfig['bundle_check_description'] ?? null;
?>
<?= topbar('Bundle Check', 'Find orders missing required companion items') ?>

<?= featureInfoStart('bundlecheck', 'Bundle Check') ?>
  <?php if ($_bcDesc): ?>
    <p><?= esc($_bcDesc) ?></p>
  <?php else: ?>
    <p><strong>Bundle Check</strong> scans Shopify orders in a date range and flags any order whose type requires specific companion items that are not present in the order.</p>
    <p>This covers the case where a customer places the main order and required accessories are added afterwards — catching orders where that step was missed.</p>
  <?php endif; ?>
  <ul>
    <li>Required items per order type are configured in <code>order_types.json</code> via the <code>required_items</code> field on each rule.</li>
    <li>Only paid, non-cancelled orders with shipping are scanned.</li>
    <?php if ($_bcTypeNames): ?>
      <li>Active bundle rules: <strong><?= esc($_bcTypeNames) ?></strong>.</li>
    <?php endif; ?>
    <li>Fulfilled orders are included — an order that shipped without all required items is the most urgent case.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Select date range</h2>
  <div class="hint">Fetches paid, non-cancelled orders and checks each one for missing required items.</div>

  <?php if ($bcError): ?>
    <div class="error-msg mb-3"><?= esc($bcError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_bundle">
    <?php
      $partialStartName  = 'bc_start'; $partialStartVal = $bcStart;
      $partialEndName    = 'bc_end';   $partialEndVal   = $bcEnd;
      $partialSubmitLabel = 'Scan orders';
      require __DIR__ . '/partials/_date-range.php';
    ?>
  </form>
</div>

<?php if ($bcResult !== null): ?>
  <?php
    $rows    = $bcResult['rows'];
    $count   = count($rows);
    $scanned = $bcResult['scanned'];
  ?>

  <div class="flex items-center gap-2 flex-wrap mb-4">
    <span class="text-xs text-muted"><?= $scanned ?> orders scanned &mdash;</span>
    <?php if ($count === 0): ?>
      <span class="source-badge live">All clear — no incomplete bundles found</span>
    <?php else: ?>
      <span class="source-badge cached"><?= $count ?> order<?= $count !== 1 ? 's' : '' ?> with missing items</span>
    <?php endif; ?>
  </div>

  <?php if ($count === 0): ?>
    <div class="empty">
      <div class="icon">✅</div>
      <h3>All bundles complete!</h3>
      <p>Every order in this date range has all required companion items.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Incomplete Bundles</h2>
        <div class="flex items-center gap-2">
          <span><?= $count ?> order<?= $count !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-bundlecheck" data-csv-filename="bundle-check-<?= esc($bcResult['end']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Type</th>
            <th>Missing Items</th>
            <th>Fulfillment</th>
            <th>Financial</th>
            <th>Email</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody id="tbl-bundlecheck">
          <?php foreach ($rows as $row):
            $shopifyId  = $row['shopify_id'] ?? '';
            $orderNum   = ltrim($row['order_number'] ?? '', '#');
            $adminUrl   = $shopifyId ? $shopifyAdminBase . '/' . esc($shopifyId) : null;
            $ssSearchUrl = 'https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch=' . urlencode($orderNum);
            $orderType  = $row['order_type'] ?? '';
            $typeClass  = 'chip-type-' . (crc32($orderType) % 6 + 6) % 6;
            $finClass   = financialChip($row['financial_status'] ?? '');
            $fulStatus  = $row['fulfillment_status'] ?? '';
            $fulClass   = match($fulStatus) {
                'fulfilled' => 'chip-paid',
                'partial'   => 'chip-partial',
                default     => 'chip-unknown',
            };
          ?>
          <tr>
            <td>
              <?php if ($adminUrl): ?>
                <a class="order-num" href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a>
              <?php else: ?>
                <span class="order-num"><?= esc($row['order_number']) ?></span>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc($orderNum) ?>" title="Copy order number">⧉</button>
            </td>
            <td><?= esc($row['created_at']) ?></td>
            <td><span class="chip <?= $typeClass ?>"><?= esc($orderType) ?></span></td>
            <td>
              <?php foreach ($row['missing_required'] as $typeName => $items): ?>
                <?php foreach ($items as $item): ?>
                  <span class="chip chip-warn">⚠ <?= esc($item) ?></span>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </td>
            <td><span class="chip <?= $fulClass ?>"><?= esc($fulStatus ?: 'unfulfilled') ?></span></td>
            <td><span class="chip <?= $finClass ?>"><?= esc($row['financial_status'] ?? '-') ?></span></td>
            <td class="td-email"><?= esc($row['email'] ?? '-') ?></td>
            <td class="td-actions">
              <a class="ignore-btn" href="<?= esc($ssSearchUrl) ?>" target="_blank" rel="noopener">Search SS</a>
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode($orderNum) ?>">Re-check</a>
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Shopify</a>
                <a class="ignore-btn" href="?page=timeline&order=<?= urlencode($orderNum) ?>">Timeline</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
