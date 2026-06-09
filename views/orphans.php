<?= topbar('Orphan Detector', 'ShipStation orders with no matching Shopify order') ?>

<?= featureInfoStart('orphans', 'Orphan Detector') ?>
    <p><strong>Orphan Detector</strong> is the reverse of the standard audit. Instead of finding Shopify orders missing from ShipStation, it finds <em>ShipStation orders that have no matching Shopify order</em> in the same date range.</p>
    <p>Orphan orders in ShipStation typically indicate one of the following:</p>
    <ul>
      <li>Orders <strong>manually created</strong> directly in ShipStation (outside of Shopify).</li>
      <li><strong>Test or dummy orders</strong> that were never placed through the storefront.</li>
      <li>Orders <strong>imported from another channel</strong> (Amazon, eBay, CSV import).</li>
      <li>Orders from a <strong>Shopify store that was deleted or disconnected</strong>.</li>
      <li>Potential <strong>data entry errors</strong> - wrong order number entered in SS.</li>
    </ul>
    <p>Matching is done by normalised order number. Both datasets are cached, so re-scanning the same range is instant.</p>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches orders from both ShipStation and Shopify and finds SS orders with no Shopify counterpart.</div>

  <?php if ($orphanError): ?>
    <div class="error-msg mb-3"><?= esc($orphanError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="find_orphans">
    <?php dateRangePartial('orphan', $orphanStart, $orphanEnd) ?>
  </form>

  <?php if ($orphanResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= $orphanResult['ss_total'] ?></strong> SS orders vs
        <strong><?= $orphanResult['sh_total'] ?></strong> Shopify orders
        (<?= esc($orphanResult['start']) ?> → <?= esc($orphanResult['end']) ?>)
        &mdash; <strong><?= count($orphanResult['rows']) ?></strong> orphan<?= count($orphanResult['rows']) !== 1 ? 's' : '' ?> found
      </span>
    </div>
  <?php endif; ?>
</div>

<?php if ($orphanResult !== null): ?>
  <?php if (empty($orphanResult['rows'])): ?>
    <?= tableWrapEmpty('No orphans found', 'Every ShipStation order in this date range has a matching Shopify order.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($orphanResult['rows'], 'tbl-orphans', 'Orphan Orders', 'orphans', $orphanResult['start']) ?>
      <table id="tbl-orphans">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Email</th>
            <th>SS Status</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orphanResult['rows'] as $row):
            $statusChip = match($row['order_status']) {
              'shipped'           => 'chip-paid',
              'awaiting_shipment' => 'chip-partial',
              'cancelled'         => 'chip-unpaid',
              default             => 'chip-unknown',
            };
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $row['ss_url'], $row['order_number']) ?>
            <td><?= esc($row['order_date']) ?></td>
            <td><?= esc($row['customer'] ?: '-') ?></td>
            <td class="td-email"><?= esc($row['email'] ?: '-') ?></td>
            <td><span class="chip <?= $statusChip ?>"><?= esc(str_replace('_', ' ', $row['order_status'])) ?></span></td>
            <td class="td-price"><?= formatPrice($row['total'] ?: null) ?></td>
            <?= actionLinks(['ssUrl' => $row['ss_url'], 'orderNum' => $row['order_number'], 'spotcheck' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
