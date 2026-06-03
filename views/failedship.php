<?= topbar('Voided Shipments', 'ShipStation shipments that were voided in the selected date range') ?>

<?= featureInfoStart('failedship', 'Voided Shipments') ?>
    <p><strong>Voided Shipments</strong> fetches shipments from ShipStation that were voided in the selected date range. Voided labels often indicate a failed delivery, a return, or a label that was created in error. Use this to proactively follow up with customers.</p>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches shipments voided in ShipStation within the selected range.</div>

  <?php if ($fsError): ?>
    <div class="error-msg mb-3"><?= esc($fsError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_failed_shipments">
    <?php
$partialStartName = 'fs_start'; $partialStartVal = $fsStart;
$partialEndName   = 'fs_end';   $partialEndVal   = $fsEnd;
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($fsResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        (<?= esc($fsResult['start']) ?> → <?= esc($fsResult['end']) ?>)
        &mdash; <strong><?= count($fsResult['rows']) ?></strong> voided shipment<?= count($fsResult['rows']) !== 1 ? 's' : '' ?> found</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($fsResult !== null): ?>
  <?php if (empty($fsResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No voided shipments found</h3>
        <p>No shipments were voided in ShipStation during this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Voided Shipments</h2>
        <div class="flex items-center gap-2">
          <span><?= count($fsResult['rows']) ?> shipment<?= count($fsResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-failedship"
                  data-csv-filename="voided-shipments-<?= esc($fsResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-failedship">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Void Date</th>
            <th>Ship Date</th>
            <th>Carrier</th>
            <th>Service</th>
            <th>Tracking</th>
            <th>Ship To</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fsResult['rows'] as $row): ?>
          <tr>
            <td class="order-num">
              <?= esc($row['order_number']) ?>
              <button class="copy-btn" data-copy="<?= esc($row['order_number']) ?>" title="Copy">⧉</button>
            </td>
            <td><?= esc($row['void_date']) ?></td>
            <td><?= esc($row['ship_date']) ?: '-' ?></td>
            <td><?= esc(strtoupper($row['carrier'])) ?: '-' ?></td>
            <td><?= esc($row['service']) ?: '-' ?></td>
            <td class="td-email"><?= esc($row['tracking']) ?: '-' ?></td>
            <td class="td-email">
              <?php if ($row['ship_to_name']): ?>
                <div class="font-medium"><?= esc($row['ship_to_name']) ?></div>
              <?php endif; ?>
              <?php
                $shipTo = implode(', ', array_filter([
                  $row['ship_to_city'],
                  $row['ship_to_state'],
                  $row['ship_to_zip'],
                  $row['ship_to_country'],
                ]));
              ?>
              <?php if ($shipTo): ?>
                <div class="text-xs text-muted"><?= esc($shipTo) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode($row['order_number']) ?>">Spot-check</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
