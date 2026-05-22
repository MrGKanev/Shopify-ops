<div class="topbar">
  <div>
    <h1>Address Scanner</h1>
    <div class="meta">Find paid orders with incomplete or potentially invalid shipping addresses</div>
  </div>
</div>

<div class="feature-info" data-info-key="addrcheck">
  <button class="feature-info-toggle" aria-expanded="false"><svg width="12" height="12" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> About: Address Scanner</button>
  <div class="feature-info-body">
    <p><strong>Address Scanner</strong> fetches all paid Shopify orders in the selected date range and runs a set of validation checks on each shipping address — flagging orders that may fail delivery before they are ever shipped.</p>
    <p>Issues are split into two severity levels:</p>
    <ul>
      <li><strong>Critical</strong> — the address is almost certainly undeliverable: missing street, city, ZIP, country, or recipient name.</li>
      <li><strong>Warning</strong> — the address may cause problems: invalid ZIP format for US/CA, missing state/province, PO Box address (always flagged — use the <em>PO Box only</em> filter to isolate these), or no phone number on an express shipment.</li>
    </ul>
    <p>Critical issues are sorted to the top. Each row links directly to the Shopify order and to Spot-check for a live ShipStation cross-reference.</p>
  </div>
</div>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches all paid and partially paid orders in the range and validates their shipping addresses.</div>

  <?php if ($addrError): ?>
    <div class="error-msg mb-3"><?= esc($addrError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_addresses">
    <div class="date-row">
      <div class="field">
        <label>From</label>
        <input type="date" name="addr_start" value="<?= esc($addrStart) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To</label>
        <input type="date" name="addr_end" value="<?= esc($addrEnd) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <button class="btn btn-submit-end" type="submit">Scan</button>
    </div>
  </form>

  <?php if ($addrResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $addrResult['scanned'] ?></strong> orders
        (<?= esc($addrResult['start']) ?> → <?= esc($addrResult['end']) ?>)
        &mdash; <strong><?= count($addrResult['rows']) ?></strong> with address issues</span>
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
          <span id="addr-count"><?= count($addrResult['rows']) ?> order<?= count($addrResult['rows']) !== 1 ? 's' : '' ?></span>
          <label class="flex items-center gap-1 text-sm cursor-pointer select-none">
            <input type="checkbox" id="filter-pobox"> PO Box only
          </label>
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
            $addrLine = implode(', ', array_filter([
              trim(($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? '')),
              $addr['city'] ?? '',
              $addr['province_code'] ?? '',
              $addr['zip'] ?? '',
              $addr['country_code'] ?? '',
            ]));
            $recipientName = trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? ''));
            $isPoBox = !empty(array_filter($row['issues'], fn($i) => in_array($i['code'], ['po_box', 'po_box_carrier'])));
          ?>
          <tr<?= $isPoBox ? ' data-pobox="1"' : '' ?>>
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

<script>
(function () {
  const cb = document.getElementById('filter-pobox');
  if (!cb) return;
  cb.addEventListener('change', function () {
    const rows  = document.querySelectorAll('#tbl-addrcheck tbody tr');
    let visible = 0;
    rows.forEach(function (tr) {
      const show = !cb.checked || tr.dataset.pobox === '1';
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    const countEl = document.getElementById('addr-count');
    if (countEl) countEl.textContent = visible + ' order' + (visible !== 1 ? 's' : '');
  });
}());
</script>
