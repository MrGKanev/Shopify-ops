<?= topbar('Active SS Conflicts', 'Refunded or cancelled Shopify orders still active in ShipStation') ?>

<?= featureInfoStart('activess', 'Active SS Conflicts') ?>
  <p><strong>Active SS Conflicts</strong> finds Shopify orders that are refunded or cancelled but still sit in ShipStation active queues.</p>
  <ul>
    <li>Shopify side scans refunded, partially refunded, and cancelled orders in the date range.</li>
    <li>ShipStation side checks <strong>awaiting payment</strong>, <strong>awaiting shipment</strong>, and <strong>on hold</strong>.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Use this before fulfillment cutoffs to catch orders that should be held, cancelled, or manually reviewed in ShipStation.</div>
  <?php if ($asError): ?><div class="error-msg mb-3"><?= esc($asError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="scan_activess">
    <?php dateRangePartial('as', $asStart, $asEnd) ?>
  </form>
  <?php if ($asResult !== null): ?>
    <div class="duration-note mt-4 mb-0">Scanned <strong><?= $asResult['scanned'] ?></strong> Shopify exception orders and <strong><?= $asResult['active_ss'] ?></strong> active ShipStation orders - <strong><?= count($asResult['rows']) ?></strong> conflicts</div>
  <?php endif; ?>
</div>

<?php if ($asResult !== null): ?>
  <?php if (empty($asResult['rows'])): ?>
    <?= tableWrapEmpty('No active conflicts', 'No refunded or cancelled Shopify orders were active in ShipStation.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($asResult['rows'], 'tbl-activess', 'Active Conflicts', 'active-ss-conflicts', $asResult['start'], 'conflict', 'Filter by order #, status, email…') ?>
      <table id="tbl-activess">
        <thead><tr><th>Order</th><th>Issue</th><th>Shopify Date</th><th>Email</th><th>Total</th><th>SS Status</th><th>SS Date</th><th>SS Total</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($asResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $ssUrl = $row['ss_order_id'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$row['ss_order_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td><span class="chip chip-unpaid"><?= esc($row['issue']) ?></span></td>
            <td><?= esc($row['created_at']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td><span class="chip chip-partial"><?= esc(str_replace('_', ' ', $row['ss_status'])) ?></span></td>
            <td><?= esc($row['ss_date']) ?></td>
            <td class="td-price"><?= formatPrice($row['ss_total']) ?></td>
            <?= actionLinks(['ssUrl' => $ssUrl, 'shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true, 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
