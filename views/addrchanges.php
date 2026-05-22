<div class="topbar">
  <div>
    <h1>Address Changes</h1>
    <div class="meta">Orders whose shipping address was edited after the order was placed</div>
  </div>
</div>

<div class="feature-info" data-info-key="addrchanges">
  <button class="feature-info-toggle" aria-expanded="false"><svg width="12" height="12" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> About: Address Changes</button>
  <div class="feature-info-body">
    <p><strong>Address Changes</strong> uses Shopify's Events API to find orders where the shipping address was modified after the order was placed. This is useful for catching last-minute customer requests, fraudulent address swaps, or support edits that may not have reached ShipStation in time.</p>
    <ul>
      <li>Only orders with an explicit <em>shipping address updated</em> event are returned — not just any order edit.</li>
      <li>The <strong>Changed</strong> column shows when the address was last modified, not when the order was created.</li>
      <li>Large date ranges may take longer as the Events API is paginated separately from orders.</li>
    </ul>
  </div>
</div>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Searches Shopify order events for shipping address changes in the selected window.</div>

  <?php if ($acError): ?>
    <div class="error-msg mb-3"><?= esc($acError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_addr_changes">
    <div class="date-row">
      <div class="field">
        <label>From</label>
        <input type="date" name="ac_start" value="<?= esc($acStart) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To</label>
        <input type="date" name="ac_end" value="<?= esc($acEnd) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <button class="btn btn-submit-end" type="submit">Scan</button>
    </div>
  </form>

  <?php if ($acResult !== null): ?>
    <div class="duration-note mt-4 mb-0">
      <span>Found <strong><?= count($acResult['rows']) ?></strong> order<?= count($acResult['rows']) !== 1 ? 's' : '' ?> with address changes
        (<?= esc($acResult['start']) ?> → <?= esc($acResult['end']) ?>)</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($acResult !== null): ?>
  <?php if (empty($acResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No address changes found</h3>
        <p>No shipping address edits detected in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Address Changes</h2>
        <div class="flex items-center gap-2">
          <span><?= count($acResult['rows']) ?> order<?= count($acResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-addrchanges"
                  data-csv-filename="address-changes-<?= esc($acResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-addrchanges">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Changed</th>
            <th>Email</th>
            <th>Current shipping address</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($acResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
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
            <td class="font-medium" style="color:var(--warn)"><?= esc($row['changed_at']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-email">
              <?php if ($row['addr_name']): ?>
                <div class="font-medium"><?= esc($row['addr_name']) ?></div>
              <?php endif; ?>
              <?php if ($row['addr_line']): ?>
                <div class="text-xs text-muted"><?= esc($row['addr_line']) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-price">$<?= number_format((float)$row['total'], 2) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <span class="chip chip-unknown capitalize"><?= esc($row['financial']) ?></span>
                <?php if ($row['fulfillment']): ?>
                  <span class="chip chip-unknown capitalize"><?= esc($row['fulfillment']) ?></span>
                <?php endif; ?>
              </div>
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
