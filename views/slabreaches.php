<?= topbar('Fulfillment SLA Breaches', 'Orders that exceeded the shipping SLA by method and region') ?>

<?= featureInfoStart('slabreaches', 'Fulfillment SLA Breaches') ?>
  <p><strong>Fulfillment SLA Breaches</strong> checks paid Shopify orders and flags orders that took longer than the configured number of days to reach first fulfillment.</p>
  <ul>
    <li>Open orders are measured from order placement to today.</li>
    <li>Fulfilled orders are measured from order placement to first fulfillment.</li>
    <li>Results include shipping method, destination region, and configured order type.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Find orders whose time-to-first-fulfillment is above your operational SLA.</div>
  <?php if ($slaError): ?><div class="error-msg mb-3"><?= esc($slaError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="scan_sla">
    <?php dateRangePartial('sla', $slaStart, $slaEnd, '<div class="field"><label>SLA days</label><input type="number" name="sla_threshold" min="1" value="' . esc($slaThreshold) . '"></div>') ?>
  </form>
  <?php if ($slaResult !== null): ?>
    <div class="duration-note mt-4 mb-0">Scanned <strong><?= $slaResult['scanned'] ?></strong> orders (<?= esc($slaResult['start']) ?> → <?= esc($slaResult['end']) ?>) - <strong><?= count($slaResult['rows']) ?></strong> breached <?= (int)$slaResult['threshold'] ?> day SLA</div>
  <?php endif; ?>
</div>

<?php if ($slaResult !== null): ?>
  <?php if (empty($slaResult['rows'])): ?>
    <?= tableWrapEmpty('No SLA breaches', 'No scanned orders exceeded the configured SLA.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($slaResult['rows'], 'tbl-sla', 'SLA Breaches', 'sla-breaches', $slaResult['start'], 'order', 'Filter by order #, method, region, type…') ?>
      <table id="tbl-sla">
        <thead><tr><th>Order</th><th>Placed</th><th>Fulfilled</th><th>Days</th><th>Method</th><th>Region</th><th>Type</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($slaResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td><?= esc($row['created_at']) ?></td>
            <td><?= esc($row['fulfilled_at'] ?: '-') ?></td>
            <td><span class="chip <?= $row['days'] >= 14 ? 'chip-unpaid' : 'chip-partial' ?>"><?= (int)$row['days'] ?>d</span></td>
            <td><?= esc($row['method']) ?></td>
            <td><?= esc($row['region']) ?></td>
            <td><span class="chip chip-unknown"><?= esc($row['order_type']) ?></span></td>
            <td>
              <span class="chip <?= financialChip($row['financial']) ?>"><?= esc($row['financial']) ?></span>
              <span class="chip chip-partial"><?= esc(str_replace('_', ' ', $row['fulfillment'])) ?></span>
            </td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true, 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
