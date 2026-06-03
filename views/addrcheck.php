<?= topbar('Address Scanner', 'Find paid orders with incomplete or potentially invalid shipping addresses') ?>

<?= featureInfoStart('addrcheck', 'Address Scanner') ?>
    <p><strong>Address Scanner</strong> fetches all paid Shopify orders in the selected date range and runs a set of validation checks on each shipping address - flagging orders that may fail delivery before they are ever shipped.</p>
    <p>Issues are split into two severity levels:</p>
    <ul>
      <li><strong>Critical</strong> - the address is almost certainly undeliverable: missing street, city, ZIP, country, or recipient name.</li>
      <li><strong>Warning</strong> - the address may cause problems: invalid ZIP format for US/CA, missing state/province, PO Box address (always flagged - use the <em>PO Box only</em> filter to isolate these), or no phone number on an express shipment.</li>
    </ul>
    <p>Critical issues are sorted to the top. Each row links directly to the Shopify order and to Spot-check for a live ShipStation cross-reference.</p>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches all paid and partially paid orders in the range and validates their shipping addresses.</div>

  <?php if ($addrError): ?>
    <div class="error-msg mb-3"><?= esc($addrError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_addresses">
    <?php
    $partialStartName = 'addr_start'; $partialStartVal = $addrStart;
    $partialEndName   = 'addr_end';   $partialEndVal   = $addrEnd;
    require __DIR__ . '/partials/_date-range.php';
    ?>
    <div class="filter-row">
      <label class="toggle-pill">
        <input type="checkbox" name="po_box_only" value="1"<?= !empty($poBoxOnly) ? ' checked' : '' ?>>
        <span class="toggle-pill-track"><span class="toggle-pill-thumb"></span></span>
        <span class="toggle-pill-label">PO Box only</span>
      </label>
      <label class="toggle-pill">
        <input type="checkbox" name="unfulfilled_only" value="1"<?= !empty($unfulfilledOnly) ? ' checked' : '' ?>>
        <span class="toggle-pill-track"><span class="toggle-pill-thumb"></span></span>
        <span class="toggle-pill-label">Unfulfilled only</span>
      </label>
    </div>
  </form>

  <?php if ($addrResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <?php if (!empty($addrResult['po_box_only'])): ?>
        <span>Scanned <strong><?= $addrResult['scanned'] ?></strong> orders
          (<?= esc($addrResult['start']) ?> → <?= esc($addrResult['end']) ?>)
          &mdash; <strong><?= count($addrResult['rows']) ?></strong> PO Box orders</span>
      <?php else: ?>
        <span>Scanned <strong><?= $addrResult['scanned'] ?></strong> orders
          (<?= esc($addrResult['start']) ?> → <?= esc($addrResult['end']) ?>)
          &mdash; <strong><?= count($addrResult['rows']) ?></strong> with address issues</span>
      <?php endif; ?>
      <?php if ($addrResult['critical'] > 0): ?>
        <span class="refund-risk-badge refund-risk-active"><?= $addrResult['critical'] ?> critical</span>
      <?php endif; ?>
      <?php if ($addrResult['warnings'] > 0): ?>
        <span class="refund-risk-badge refund-risk-missing"><?= $addrResult['warnings'] ?> warnings</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($addrResult !== null): ?>
  <?php if (empty($addrResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>All addresses look good</h3>
        <p>No address problems found in <?= $addrResult['scanned'] ?> orders for this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Address Issues</h2>
        <div class="flex items-center gap-2">
          <span><?= count($addrResult['rows']) ?> order<?= count($addrResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-addrcheck"
                  data-csv-filename="address-issues-<?= esc($addrResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-addrcheck">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Shipping address</th>
            <th>Issues</th>
            <th>Severity</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($addrResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $addr     = $row['address'];
            $addrLine = formatAddressLine($addr);
            $recipientName = trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? ''));
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
            <td class="td-email">
              <?php if ($recipientName): ?>
                <div class="font-medium"><?= esc($recipientName) ?></div>
              <?php endif; ?>
              <?php if ($addrLine): ?>
                <div class="text-xs text-muted"><?= esc($addrLine) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['issues'] as $issue): ?>
                  <span class="addr-issue addr-issue-<?= $issue['level'] ?>">
                    <?= esc($issue['message']) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </td>
            <td>
              <span class="refund-risk-badge <?= $row['severity'] === 'critical' ? 'refund-risk-active' : 'refund-risk-missing' ?>">
                <?= $row['severity'] ?>
              </span>
            </td>
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Edit in Shopify</a>
              <?php endif; ?>
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
