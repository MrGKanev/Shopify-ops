<?= topbar('Note Flags', 'Paid unfulfilled orders with flagged keywords in notes') ?>

<?= featureInfoStart('noteflags', 'Note Flags') ?>
  <p><strong>Note Flags</strong> scans paid, unfulfilled orders for specific keywords in the order note field — surfacing orders that need immediate attention before shipment.</p>
  <ul>
    <li>Only <strong>paid or partially-paid, unfulfilled or partial</strong> orders are scanned — already-fulfilled orders are excluded.</li>
    <li>Keyword matching is <strong>case-insensitive</strong> and checks for substring presence (e.g. <code>cancel</code> matches <em>please cancel this order</em>).</li>
    <li>Customise the keyword list to match your team's internal flags.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid, unfulfilled orders and checks each note for the configured keywords.</div>

  <?php if ($nfError): ?>
    <div class="error-msg mb-3"><?= esc($nfError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_noteflags">
    <?php dateRangePartial('nf', $nfStart, $nfEnd, '<div class="field" style="min-width:260px"><label>Keywords (comma-separated)</label><input type="text" name="nf_keywords" value="' . esc($nfKeywordsRaw) . '" style="width:100%"></div>') ?>
  </form>

  <?php if ($nfResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $nfResult['scanned'] ?></strong> order<?= $nfResult['scanned'] !== 1 ? 's' : '' ?>
        (<?= esc($nfResult['start']) ?> → <?= esc($nfResult['end']) ?>)
        &mdash; <strong><?= count($nfResult['rows']) ?></strong> matched</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($nfResult !== null): ?>
  <?php if (empty($nfResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No flagged orders</h3>
        <p>None of the <?= $nfResult['scanned'] ?> scanned orders contained the configured keywords.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Flagged Orders</h2>
        <div class="flex items-center gap-2">
          <span><?= count($nfResult['rows']) ?> order<?= count($nfResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-noteflags"
                  data-csv-filename="note-flags-<?= esc($nfResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-noteflags', 'Filter by order #, email, note…') ?>
      <table id="tbl-noteflags">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Matched</th>
            <th>Note</th>
            <th>Email</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nfResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['matched'] as $kw): ?>
                  <span class="chip chip-unpaid"><?= esc($kw) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="text-sm" style="max-width:320px;white-space:pre-wrap;word-break:break-word"><?= esc($row['note']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td>
              <span class="chip <?= financialChip($row['financial']) ?>"><?= esc($row['financial']) ?></span>
              <?php if ($row['fulfillment']): ?>
                <span class="chip chip-partial"><?= esc(str_replace('_', ' ', $row['fulfillment'])) ?></span>
              <?php endif; ?>
            </td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
