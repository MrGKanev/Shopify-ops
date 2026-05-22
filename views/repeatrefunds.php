<div class="topbar">
  <div>
    <h1>Repeat Refunds</h1>
    <div class="meta">Customers with multiple refunded orders in a date range</div>
  </div>
</div>

<div class="feature-info" data-info-key="repeatrefunds">
  <button class="feature-info-toggle" aria-expanded="false"><svg width="12" height="12" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> About: Repeat Refunds</button>
  <div class="feature-info-body">
    <p><strong>Repeat Refunds</strong> identifies customers who have received refunds on two or more orders within the selected date range. This can help spot patterns of abuse, serial returners, or persistent fulfilment issues with a specific customer segment.</p>
    <p>Results are grouped by email address and sorted by refund count descending. Click through to the Customer page for a full order history.</p>
  </div>
</div>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches refunded orders and groups them by customer email to find repeat refund recipients.</div>

  <?php if ($rrError): ?>
    <div class="error-msg mb-3"><?= esc($rrError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_repeat_refunds">
    <div class="date-row">
      <div class="field">
        <label>From</label>
        <input type="date" name="rr_start" value="<?= esc($rrStart) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To</label>
        <input type="date" name="rr_end" value="<?= esc($rrEnd) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>Min refund count</label>
        <input type="number" name="rr_min_count" value="<?= (int)$rrMinCount ?>" min="2" style="width:80px">
      </div>
      <button class="btn btn-submit-end" type="submit">Scan</button>
    </div>
  </form>

  <?php if ($rrResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $rrResult['scanned'] ?></strong> refunded orders
        (<?= esc($rrResult['start']) ?> → <?= esc($rrResult['end']) ?>)
        &mdash; <strong><?= count($rrResult['rows']) ?></strong> customer<?= count($rrResult['rows']) !== 1 ? 's' : '' ?> with &ge; <?= (int)$rrResult['min_count'] ?> refunds</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($rrResult !== null): ?>
  <?php if (empty($rrResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No repeat refund customers found</h3>
        <p>No customers have <?= (int)$rrResult['min_count'] ?> or more refunds in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Repeat Refund Customers</h2>
        <div class="flex items-center gap-2">
          <span><?= count($rrResult['rows']) ?> customer<?= count($rrResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-repeatrefunds"
                  data-csv-filename="repeat-refunds-<?= esc($rrResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-repeatrefunds">
        <thead>
          <tr>
            <th>Email</th>
            <th>Refund Count</th>
            <th>Total Refunded</th>
            <th>Orders</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rrResult['rows'] as $row): ?>
          <tr>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td><strong><?= (int)$row['refund_count'] ?></strong></td>
            <td class="td-price">$<?= number_format($row['total_refunded'], 2) ?></td>
            <td>
              <div class="flex flex-wrap gap-1">
                <?php foreach ($row['orders'] as $o):
                  $oAdminUrl = $o['shopify_id'] ? $shopifyAdminBase . '/' . esc($o['shopify_id']) : null;
                ?>
                  <?php if ($oAdminUrl): ?>
                    <a href="<?= $oAdminUrl ?>" target="_blank" rel="noopener" class="chip chip-partial" title="<?= esc($o['created_at']) ?><?= $o['refunded_amt'] ? ' — $' . number_format($o['refunded_amt'], 2) . ' refunded' : '' ?>"><?= esc($o['order_number']) ?></a>
                  <?php else: ?>
                    <span class="chip chip-partial" title="<?= esc($o['created_at']) ?>"><?= esc($o['order_number']) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="td-actions">
              <a class="ignore-btn" href="?page=customer&email=<?= urlencode($row['email']) ?>">Customer</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
