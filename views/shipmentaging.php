<?= topbar('Shipment Aging', 'ShipStation awaiting-shipment orders over threshold') ?>

<?= featureInfoStart('shipmentaging', 'Shipment Aging') ?>
  <p><strong>Shipment Aging</strong> scans the live ShipStation awaiting-shipment queue and flags orders older than the configured threshold.</p>
  <ul>
    <li>Summaries group aging orders by SKU and configured order type.</li>
    <li>This is a live ShipStation check and does not need a Shopify date range.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Queue threshold</h2>
  <div class="hint">Find awaiting-shipment orders that have been waiting too long.</div>
  <?php if ($saError): ?><div class="error-msg mb-3"><?= esc($saError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="scan_shipmentaging">
    <div class="date-row">
      <div class="field"><label>Older than days</label><input type="number" name="sa_threshold" min="1" value="<?= esc($saThreshold) ?>"></div>
      <button class="btn btn-submit-end" type="submit">Scan</button>
    </div>
  </form>
  <?php if ($saResult !== null): ?>
    <div class="duration-note mt-4 mb-0">Scanned <strong><?= $saResult['scanned'] ?></strong> awaiting orders — <strong><?= count($saResult['rows']) ?></strong> older than <?= (int)$saResult['threshold'] ?> days</div>
  <?php endif; ?>
</div>

<?php if ($saResult !== null): ?>
  <?php if (empty($saResult['rows'])): ?>
    <?= tableWrapEmpty('No aging shipments', 'No awaiting-shipment orders exceeded the configured threshold.') ?>
  <?php else: ?>
    <div class="db-two-col mb-6">
      <div>
        <div class="db-section-title">By SKU</div>
        <div class="db-panel" style="padding:.75rem 1rem">
          <?php foreach (array_slice($saResult['by_sku'], 0, 8) as $sku): ?>
            <div class="db-history-row">
              <div class="db-history-date" style="width:auto;flex:1"><?= esc($sku['sku']) ?></div>
              <div class="text-sm"><?= (int)$sku['orders'] ?> orders · <?= (int)$sku['qty'] ?> qty · oldest <?= (int)$sku['oldest_days'] ?>d</div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div class="db-section-title">By Type</div>
        <div class="db-panel" style="padding:.75rem 1rem">
          <?php foreach (array_slice($saResult['by_type'], 0, 8) as $type): ?>
            <div class="db-history-row">
              <div class="db-history-date" style="width:auto;flex:1"><?= esc($type['type']) ?></div>
              <div class="text-sm"><?= (int)$type['orders'] ?> orders · oldest <?= (int)$type['oldest_days'] ?>d</div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="table-wrap">
      <?= tableWrapHeader($saResult['rows'], 'tbl-shipmentaging', 'Aging Shipments', 'shipment-aging', date('Y-m-d'), 'order', 'Filter by order #, SKU, customer, type…') ?>
      <table id="tbl-shipmentaging">
        <thead><tr><th>Order</th><th>Date</th><th>Days</th><th>Customer</th><th>Total</th><th>Type</th><th>SKUs</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($saResult['rows'] as $row):
            $ssUrl = $row['ss_order_id'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode((string)$row['ss_order_id']) : null;
          ?>
          <tr>
            <td class="order-num">
              <?php if ($ssUrl): ?><a href="<?= esc($ssUrl) ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a><?php else: ?><?= esc($row['order_number']) ?><?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc($row['order_number']) ?>" title="Copy">⧉</button>
            </td>
            <td><?= esc($row['order_date']) ?></td>
            <td><span class="chip <?= $row['days'] >= 14 ? 'chip-unpaid' : 'chip-partial' ?>"><?= (int)$row['days'] ?>d</span></td>
            <td><?= esc($row['customer']) ?><br><span class="text-xs text-muted"><?= esc($row['email']) ?></span></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td><span class="chip chip-unknown"><?= esc($row['order_type']) ?></span></td>
            <td class="text-sm">
              <?php foreach ($row['skus'] as $sku => $qty): ?>
                <span class="chip chip-unknown"><?= esc($sku) ?> × <?= (int)$qty ?></span>
              <?php endforeach; ?>
            </td>
            <?= actionLinks(['ssUrl' => $ssUrl, 'orderNum' => $row['order_number'], 'spotcheck' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
