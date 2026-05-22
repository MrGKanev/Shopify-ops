<div class="topbar">
  <div>
    <h1>High-Value No Phone</h1>
    <div class="meta">Paid, unfulfilled high-value orders missing a shipping phone number</div>
  </div>
</div>

<div class="feature-info" data-info-key="hvorders">
  <button class="feature-info-toggle" aria-expanded="false"><svg width="12" height="12" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> About: High-Value No Phone</button>
  <div class="feature-info-body">
    <p><strong>High-Value No Phone</strong> finds paid, unfulfilled orders above a threshold where the shipping address has no phone number. Carriers often require a phone for high-value shipments.</p>
    <p>Use this to proactively reach out to customers before shipping to collect a phone number and avoid carrier rejections or delayed deliveries.</p>
  </div>
</div>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid, unfulfilled orders and filters to those above the minimum order value with no shipping phone.</div>

  <?php if ($hvError): ?>
    <div class="error-msg mb-3"><?= esc($hvError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_hvorders">
    <div class="date-row">
      <div class="field">
        <label>From</label>
        <input type="date" name="hv_start" value="<?= esc($hvStart) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To</label>
        <input type="date" name="hv_end" value="<?= esc($hvEnd) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>Min order value $</label>
        <input type="number" name="hv_min" value="<?= (int)$hvMin ?>" min="0" step="10" style="width:100px">
      </div>
      <button class="btn btn-submit-end" type="submit">Scan</button>
    </div>
  </form>

  <?php if ($hvResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $hvResult['scanned'] ?></strong> orders
        (<?= esc($hvResult['start']) ?> → <?= esc($hvResult['end']) ?>)
        &mdash; <strong><?= count($hvResult['rows']) ?></strong> high-value order<?= count($hvResult['rows']) !== 1 ? 's' : '' ?> without phone</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($hvResult !== null): ?>
  <?php if (empty($hvResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No issues found</h3>
        <p>All orders above $<?= (int)$hvResult['min'] ?> have a shipping phone number in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>High-Value Orders Without Phone</h2>
        <div class="flex items-center gap-2">
          <span><?= count($hvResult['rows']) ?> order<?= count($hvResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-hvorders"
                  data-csv-filename="hv-no-phone-<?= esc($hvResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-hvorders">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Shipping Address</th>
            <th>Order Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hvResult['rows'] as $row):
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
            <td class="td-email">
              <?php if ($row['email']): ?>
                <a href="?page=customer&email=<?= urlencode($row['email']) ?>"><?= esc($row['email']) ?></a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="td-email">
              <?php if ($recipientName): ?>
                <div class="font-medium"><?= esc($recipientName) ?></div>
              <?php endif; ?>
              <?php if ($addrLine): ?>
                <div class="text-xs text-muted"><?= esc($addrLine) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-price"><strong>$<?= number_format($row['total'], 2) ?></strong></td>
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
