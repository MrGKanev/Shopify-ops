<?= topbar('SS Shipped / Shopify Unfulfilled', 'ShipStation shipped orders that Shopify still shows as unfulfilled') ?>

<?= featureInfoStart('ssshipped', 'SS Shipped / Shopify Unfulfilled') ?>
  <p><strong>SS Shipped / Shopify Unfulfilled</strong> is the reverse of the standard audit. It finds orders that ShipStation has marked as <em>shipped</em> but that still show as <em>unfulfilled</em> (or partially fulfilled) in Shopify — a sign of a sync failure.</p>
  <ul>
    <li>Common causes: webhook delivery failure, API timeout during fulfillment sync, or a manually shipped order in ShipStation without a Shopify fulfillment hook.</li>
    <li>Orders that are <em>fulfilled</em> in both systems are excluded — only the discrepancy is shown.</li>
    <li>Orders not found in Shopify at all (true orphans) are excluded — use the <a href="?page=orphans">Orphan Detector</a> for those.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Compares ShipStation shipped orders against Shopify fulfillment status for the same range.</div>

  <?php if ($ssuError): ?>
    <div class="error-msg mb-3"><?= esc($ssuError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_ssshipped">
    <?php
$partialStartName = 'ssu_start'; $partialStartVal = $ssuStart;
$partialEndName   = 'ssu_end';   $partialEndVal   = $ssuEnd;
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($ssuResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= $ssuResult['shipped_total'] ?></strong> SS shipped orders in range
        &mdash; <strong><?= count($ssuResult['rows']) ?></strong> with Shopify sync mismatch
        (<?= esc($ssuResult['start']) ?> → <?= esc($ssuResult['end']) ?>)
      </span>
    </div>
  <?php endif; ?>
</div>

<?php if ($ssuResult !== null): ?>
  <?php if (empty($ssuResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>All ShipStation shipped orders are synced</h3>
        <p>Every shipped order in this range has a corresponding fulfilled status in Shopify.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Sync Mismatches</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ssuResult['rows']) ?> order<?= count($ssuResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-ssshipped"
                  data-csv-filename="ss-shipped-mismatch-<?= esc($ssuResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <div class="search-wrap mb-3">
        <input class="js-search" data-target="tbl-ssshipped" placeholder="Filter by order #, email…" type="search">
      </div>
      <table id="tbl-ssshipped">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Email</th>
            <th>SS status</th>
            <th>Shopify status</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ssuResult['rows'] as $row):
            $shopifyUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $shChip     = match($row['sh_fulfillment']) {
                'unfulfilled' => 'chip-unpaid',
                'partial'     => 'chip-partial',
                default       => 'chip-unknown',
            };
          ?>
          <tr>
            <td class="order-num">
              <?php if ($row['ss_url']): ?>
                <a href="<?= esc($row['ss_url']) ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a>
              <?php else: ?>
                <?= esc($row['order_number']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc($row['order_number']) ?>" title="Copy">⧉</button>
            </td>
            <td class="text-sm"><?= esc($row['order_date']) ?></td>
            <td><?= esc($row['customer'] ?: '-') ?></td>
            <td class="td-email"><?= esc($row['email'] ?: '-') ?></td>
            <td><span class="chip chip-paid">shipped</span></td>
            <td><span class="chip <?= $shChip ?>"><?= esc(str_replace('_', ' ', $row['sh_fulfillment'])) ?></span></td>
            <td class="td-price"><?= formatPrice($row['total'] ?: null) ?></td>
            <td class="td-actions">
              <?php if ($row['ss_url']): ?>
                <a class="ignore-btn" href="<?= esc($row['ss_url']) ?>" target="_blank" rel="noopener">Open in SS</a>
              <?php endif; ?>
              <?php if ($shopifyUrl): ?>
                <a class="ignore-btn" href="<?= $shopifyUrl ?>" target="_blank" rel="noopener">View in Shopify</a>
              <?php endif; ?>
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode($row['order_number']) ?>">Spot-check</a>
              <a class="ignore-btn" href="?page=timeline&order=<?= urlencode($row['order_number']) ?>">Timeline</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
