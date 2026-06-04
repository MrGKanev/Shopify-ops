<?= topbar('Partial Fulfillment Stalls', 'Open orders partially shipped but stalled with unfulfilled items') ?>

<?= featureInfoStart('partialfulfill', 'Partial Fulfillment Stalls') ?>
  <p><strong>Partial Fulfillment Stalls</strong> finds open Shopify orders in <em>partially fulfilled</em> status where the remaining unfulfilled items have not moved for longer than the configured threshold.</p>
  <ul>
    <li>The <strong>Stalled for</strong> column counts days since the <em>last</em> fulfillment was created. If no fulfillment exists yet (shouldn't happen for a partial order but can), it counts from the order date.</li>
    <li><strong>Unfulfilled items</strong> are line items with a remaining <code>fulfillable_quantity &gt; 0</code>.</li>
    <li>Cancelled, fully refunded, and already closed orders are excluded automatically by Shopify's <code>status=open</code> filter.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Finds open orders created in the range that are partially shipped and stalled.</div>

  <?php if ($pfError): ?>
    <div class="error-msg mb-3"><?= esc($pfError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_partial_fulfill">
    <?php
$partialStartName = 'pf_start'; $partialStartVal = $pfStart;
$partialEndName   = 'pf_end';   $partialEndVal   = $pfEnd;
$partialExtraHtml = '<div class="field"><label>Stalled &ge; (days)</label><input type="number" name="pf_threshold" value="' . (int)$pfThreshold . '" min="1" style="width:80px"></div>';
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($pfResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $pfResult['scanned'] ?></strong> partially fulfilled orders
        (<?= esc($pfResult['start']) ?> → <?= esc($pfResult['end']) ?>)
        &mdash; <strong><?= count($pfResult['rows']) ?></strong> stalled &ge; <?= (int)$pfResult['threshold'] ?> day<?= $pfResult['threshold'] !== 1 ? 's' : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($pfResult !== null): ?>
  <?php if (empty($pfResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No stalled partial fulfillments</h3>
        <p>All <?= $pfResult['scanned'] ?> partially fulfilled orders in this range have moved within <?= (int)$pfResult['threshold'] ?> days.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Stalled Partial Fulfillments</h2>
        <div class="flex items-center gap-2">
          <span><?= count($pfResult['rows']) ?> order<?= count($pfResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-partialfulfill"
                  data-csv-filename="partial-stalls-<?= esc($pfResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <div class="search-wrap mb-3">
        <input class="js-search" data-target="tbl-partialfulfill" placeholder="Filter by order #, email, SKU…" type="search">
      </div>
      <table id="tbl-partialfulfill">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Last ship</th>
            <th>Stalled for</th>
            <th>Unfulfilled items</th>
            <th>Email</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pfResult['rows'] as $row):
            $adminUrl    = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $days        = (int)$row['days_stalled'];
            $daysColor   = $days >= 30 ? 'var(--danger)' : ($days >= 14 ? 'var(--warn)' : 'inherit');
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a>
              <?php else: ?>
                <?= esc($row['order_number']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc(ltrim($row['order_number'], '#')) ?>" title="Copy">⧉</button>
            </td>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="text-sm text-muted"><?= $row['last_fulfilled'] ? esc($row['last_fulfilled']) : '-' ?></td>
            <td class="font-semibold" style="color:<?= $daysColor ?>"><?= $days ?>d</td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['unfulfilled_items'] as $item): ?>
                  <div class="text-sm">
                    <span class="font-medium"><?= esc($item['name']) ?></span>
                    <?php if ($item['sku']): ?>
                      <span class="text-xs text-muted font-mono"> · <?= esc($item['sku']) ?></span>
                    <?php endif; ?>
                    <span class="chip chip-partial ml-1">×<?= (int)$item['qty'] ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total_price']) ?></td>
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">View in Shopify</a>
              <?php endif; ?>
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($row['order_number'], '#')) ?>">Spot-check</a>
              <a class="ignore-btn" href="?page=timeline&order=<?= urlencode(ltrim($row['order_number'], '#')) ?>">Timeline</a>
              <?php if ($row['email']): ?>
                <a class="ignore-btn" href="?page=customer&email=<?= urlencode($row['email']) ?>">Customer</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
