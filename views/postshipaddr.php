<?= topbar('Post-Ship Address Change', 'Shipping address edited after the order was fulfilled') ?>

<?= featureInfoStart('postshipaddr', 'Post-Ship Address Change') ?>
  <p><strong>Post-Ship Address Change</strong> finds orders where the shipping address was updated <em>after</em> the first fulfillment was created — meaning the package is already in transit and the new address cannot be applied.</p>
  <ul>
    <li>Different from the <a href="?page=addrchanges">Address Changes</a> audit, which flags <em>any</em> post-placement address edit.</li>
    <li>The <strong>Changed</strong> column shows when the edit happened. The <strong>Shipped</strong> column shows when the first fulfillment was created.</li>
    <li>The <strong>Δ after ship</strong> column is the gap between fulfillment creation and the address change.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Scans order address-change events and cross-checks against fulfillment creation timestamps.</div>

  <?php if ($psError): ?>
    <div class="error-msg mb-3"><?= esc($psError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_postshipaddr">
    <?php
$partialStartName = 'ps_start'; $partialStartVal = $psStart;
$partialEndName   = 'ps_end';   $partialEndVal   = $psEnd;
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($psResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Found <strong><?= count($psResult['rows']) ?></strong> post-ship address change<?= count($psResult['rows']) !== 1 ? 's' : '' ?>
        (<?= esc($psResult['start']) ?> → <?= esc($psResult['end']) ?>)</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($psResult !== null): ?>
  <?php if (empty($psResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No post-ship address changes</h3>
        <p>All address changes in this range happened before the order was fulfilled.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Post-Ship Address Changes</h2>
        <div class="flex items-center gap-2">
          <span><?= count($psResult['rows']) ?> order<?= count($psResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-postshipaddr"
                  data-csv-filename="postship-addr-<?= esc($psResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <div class="search-wrap mb-3">
        <input class="js-search" data-target="tbl-postshipaddr" placeholder="Filter by order #, email, address…" type="search">
      </div>
      <table id="tbl-postshipaddr">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Shipped</th>
            <th>Changed</th>
            <th>Δ after ship</th>
            <th>New address</th>
            <th>Email</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($psResult['rows'] as $row):
            $adminUrl   = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $mins       = (int)$row['mins_after_ship'];
            $deltaLabel = $mins < 60
              ? "{$mins}m"
              : ($mins < 1440 ? round($mins / 60, 1) . 'h' : round($mins / 1440, 1) . 'd');
            $deltaColor = $mins >= 60 ? 'var(--danger)' : 'var(--warn)';
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="text-sm"><?= esc($row['fulfillment_at']) ?></td>
            <td class="text-sm"><?= esc($row['changed_at']) ?></td>
            <td class="font-semibold" style="color:<?= $deltaColor ?>">+<?= $deltaLabel ?></td>
            <td class="text-sm">
              <?php if ($row['addr_name']): ?>
                <div class="font-medium"><?= esc($row['addr_name']) ?></div>
              <?php endif; ?>
              <div class="text-muted"><?= esc($row['addr_line']) ?></div>
            </td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
