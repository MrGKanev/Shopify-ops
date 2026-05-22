<?= topbar('Refunds Tracker', 'Refunded Shopify orders cross-checked against ShipStation status') ?>

<?= featureInfoStart('refunds', 'Refunds Tracker') ?>
  <p><strong>Refunds Tracker</strong> fetches all <em>refunded</em> and <em>partially refunded</em> Shopify orders in the selected date range and cross-checks them against ShipStation - to verify whether the corresponding SS order has been cancelled or is still active.</p>
  <p>The risk: Shopify has already returned the customer's money, but ShipStation may still ship the package. This tool identifies exactly those cases so they can be acted on before fulfilment.</p>
  <ul>
    <li><strong>Active in SS</strong> - the order is in <em>awaiting_shipment</em> or <em>on_hold</em> in ShipStation. Requires manual review or cancellation.</li>
    <li><strong>Not in SS</strong> - no matching order found in ShipStation (may be normal for orders that were never pushed).</li>
    <li><strong>OK</strong> - the SS order is cancelled, shipped, or delivered.</li>
  </ul>
  <p>Rows are sorted by risk level - the most critical appear first. ShipStation data is cached alongside the regular audit, so re-scanning the same date range is instant.</p>

<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan refunded orders</h2>
  <div class="hint">Fetches all refunded / partially refunded Shopify orders in the date range and checks whether the corresponding ShipStation order is cancelled or still active.</div>

  <?php if ($refundsError): ?>
    <div class="error-msg mb-3"><?= esc($refundsError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="find_refunds">
    <?php
$partialStartName = 'refunds_start'; $partialStartVal = $refundsStart;
$partialEndName   = 'refunds_end';   $partialEndVal   = $refundsEnd;
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($refundsResult !== null): ?>
    <?php
      $rows    = $refundsResult['rows'];
      $hasss   = $refundsResult['has_ss'];
      $active  = $refundsResult['active'];
      $missing = $refundsResult['missing'];
    ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= count($rows) ?></strong> refunded order<?= count($rows) !== 1 ? 's' : '' ?>
        &nbsp;(<?= esc($refundsResult['start']) ?> → <?= esc($refundsResult['end']) ?>)
      </span>
      <?php if ($active > 0): ?>
        <span class="source-badge" style="background:#fee2e2;color:#b91c1c">
          <?= $active ?> still active in SS
        </span>
      <?php endif; ?>
      <?php if ($missing > 0): ?>
        <span class="source-badge cached"><?= $missing ?> not found in SS</span>
      <?php endif; ?>
      <?php if (!$hasss): ?>
        <span class="source-badge" style="background:#fef9c3;color:#854d0e">SS credentials missing - SS status not available</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($refundsResult !== null): ?>
  <?php if (empty($rows)): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No refunded orders</h3>
        <p>No refunded or partially refunded orders found in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Refunded Orders</h2>
        <div class="flex items-center gap-2">
          <span><?= count($rows) ?> order<?= count($rows) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-refunds" data-csv-filename="refunds-<?= esc($refundsResult['start']) ?>-<?= esc($refundsResult['end']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-refunds">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Shopify status</th>
            <th>Order total</th>
            <th>Refunded</th>
            <?php if ($hasss): ?>
              <th>ShipStation status</th>
              <th>Risk</th>
            <?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $adminUrl  = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $finStatus = $row['financial_status'];
            $finChip   = $finStatus === 'refunded' ? 'chip-unpaid' : 'chip-partial';
            $finLabel  = $finStatus === 'partially_refunded' ? 'Partially refunded' : ucfirst($finStatus);

            $riskLabel = match($row['risk']) {
              'active'  => ['label' => 'Active in SS',  'cls' => 'refund-risk-active'],
              'missing' => ['label' => 'Not in SS',     'cls' => 'refund-risk-missing'],
              default   => ['label' => 'OK',            'cls' => 'refund-risk-ok'],
            };
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
            <td><?= esc($row['created_at']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td><span class="chip <?= $finChip ?>"><?= esc($finLabel) ?></span></td>
            <td class="td-price"><?= formatPrice($row['total_price']) ?></td>
            <td class="td-price">
              <?php if ($row['refunded_amount'] > 0): ?>
                <span style="color:var(--danger)">-$<?= number_format($row['refunded_amount'], 2) ?></span>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <?php if ($hasss): ?>
              <td>
                <?php if (empty($row['ss_orders'])): ?>
                  <span class="chip chip-unknown">Not found</span>
                <?php else: ?>
                  <div class="flex flex-wrap gap-1">
                    <?php foreach ($row['ss_statuses'] as $i => $st):
                      $ssChip = match($st) {
                        'cancelled'         => 'chip-unpaid',
                        'shipped', 'delivered' => 'chip-paid',
                        'awaiting_shipment' => 'chip-partial',
                        default             => 'chip-unknown',
                      };
                      $ssUrl = !empty($row['ss_orders'][$i]['orderId'])
                        ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($row['ss_orders'][$i]['orderId'])
                        : null;
                    ?>
                      <?php if ($ssUrl): ?>
                        <a href="<?= esc($ssUrl) ?>" target="_blank" rel="noopener"
                           class="chip <?= $ssChip ?>" style="text-decoration:none"><?= esc(str_replace('_', ' ', $st)) ?></a>
                      <?php else: ?>
                        <span class="chip <?= $ssChip ?>"><?= esc(str_replace('_', ' ', $st)) ?></span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="refund-risk-badge <?= $riskLabel['cls'] ?>"><?= $riskLabel['label'] ?></span>
              </td>
            <?php endif; ?>
            <td class="td-actions">
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($row['order_number'], '#')) ?>">Spot-check</a>
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
