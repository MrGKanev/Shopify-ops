<?php
// Helpers used only in this view
$latestCount = $latestReport['count'] ?? 0;
$latestDate  = $latestReport['date']  ?? null;
$prevCount   = isset($reports[1]) ? $reports[1]['count'] : null;

$trendIcon  = '';
$trendColor = '';
if ($dbTrend !== null) {
    if ($dbTrend < 0) { $trendIcon = '↓'; $trendColor = 'var(--ok)'; }
    elseif ($dbTrend > 0) { $trendIcon = '↑'; $trendColor = 'var(--danger)'; }
    else { $trendIcon = '→'; $trendColor = 'var(--muted)'; }
}

$latestCardMod = '';
if ($latestDate) {
    $latestCardMod = $latestCount === 0 ? 'db-card--ok' : ($latestCount >= 5 ? 'db-card--danger' : 'db-card--warn');
}
?>

<div class="topbar">
  <div>
    <h1>Dashboard</h1>
    <div class="meta">Operations overview — no API calls, instant load</div>
  </div>
  <div class="flex items-center gap-2">
    <?php if ($dbCacheCount > 0): ?>
      <span class="text-xs" style="color:var(--muted)"><?= $dbCacheCount ?> cache entries</span>
    <?php endif; ?>
    <a href="?page=run" class="btn btn-sm">▶ Run Audit</a>
  </div>
</div>

<!-- ── Stat cards ─────────────────────────────────────────────────────── -->
<div class="db-cards">

  <!-- Latest audit -->
  <div class="db-card <?= $latestCardMod ?>">
    <div class="db-card-label">Latest Audit</div>
    <div class="db-card-num">
      <?php if ($latestDate): ?>
        <?= $latestCount ?>
        <?php if ($trendIcon): ?>
          <span style="font-size:1.1rem;color:<?= $trendColor ?>"><?= $trendIcon ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span style="font-size:1.2rem;color:var(--muted)">—</span>
      <?php endif; ?>
    </div>
    <div class="db-card-sub">
      <?php if ($latestDate): ?>
        missing &middot; <?= esc($latestDate) ?>
        <?php if ($prevCount !== null && $dbTrend !== 0): ?>
          <span style="color:<?= $trendColor ?>"> (was <?= $prevCount ?>)</span>
        <?php endif; ?>
        <?php if ($dbDaysSinceAudit !== null): ?>
          <?php
            $dsa = $dbDaysSinceAudit;
            $dsaColor = $dsa <= 2 ? 'var(--ok)' : ($dsa <= 7 ? 'var(--warn)' : 'var(--danger)');
            $dsaLabel = $dsa === 0 ? 'today' : ($dsa === 1 ? '1 day ago' : "{$dsa} days ago");
          ?>
          <br><span style="color:<?= $dsaColor ?>">last run: <?= $dsaLabel ?></span>
        <?php endif; ?>
      <?php else: ?>
        No audits run yet
      <?php endif; ?>
    </div>
  </div>

  <!-- All time -->
  <div class="db-card">
    <div class="db-card-label">All-Time Reports</div>
    <div class="db-card-num"><?= $dbTotalReports ?></div>
    <div class="db-card-sub">
      <?php if ($dbTotalReports > 0): ?>
        <?= $dbTotalMissing ?> total missing orders
        <?php if ($dbAvgCadence !== null): ?>
          <br><span style="color:var(--muted)">avg every <?= $dbAvgCadence ?> day<?= $dbAvgCadence == 1 ? '' : 's' ?></span>
        <?php endif; ?>
      <?php else: ?>
        No history yet
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent pushes -->
  <div class="db-card">
    <div class="db-card-label">Pushes (30 days)</div>
    <div class="db-card-num"><?= count($dbPushRecent) ?></div>
    <div class="db-card-sub">
      <?php if ($dbLastPush): ?>
        last: <?= esc(substr($dbLastPush, 0, 10)) ?>
        <?php if ($dbAvgResolutionDays !== null): ?>
          <br><span style="color:var(--muted)">avg <?= $dbAvgResolutionDays ?> day<?= $dbAvgResolutionDays == 1 ? '' : 's' ?> to resolve</span>
        <?php endif; ?>
      <?php else: ?>
        No pushes yet
      <?php endif; ?>
    </div>
  </div>

  <!-- Ignored -->
  <div class="db-card <?= $dbStaleIgnored > 0 ? 'db-card--warn' : '' ?>">
    <div class="db-card-label">Ignored Orders</div>
    <div class="db-card-num"><?= count($ignoredOrders) ?></div>
    <div class="db-card-sub">
      <?php if ($dbStaleIgnored > 0): ?>
        <span style="color:var(--warn)"><?= $dbStaleIgnored ?> stale (60+ days)</span> &middot;
      <?php endif; ?>
      <a href="?page=ignored">Manage &rarr;</a>
    </div>
  </div>

</div>

<!-- ── Action Queue ──────────────────────────────────────────────────── -->
<div class="db-section">
  <div class="db-section-title">
    ⚠ Action Queue — Missing Orders
    <?php if ($latestDate): ?>
      <a href="?date=<?= esc($latestDate) ?>">View full report &rarr;</a>
    <?php endif; ?>
    <?php if ($dbOldestMissingDays !== null && $latestCount > 0): ?>
      <?php $omColor = $dbOldestMissingDays >= 14 ? 'var(--danger)' : ($dbOldestMissingDays >= 7 ? 'var(--warn)' : 'var(--muted)'); ?>
      <span style="font-size:.78rem;font-weight:400;color:<?= $omColor ?>">oldest order: <?= $dbOldestMissingDays ?> days old</span>
    <?php endif; ?>
  </div>
  <?php if (!empty($dbMissingByType) && $latestCount > 0): ?>
    <div style="padding:.35rem 0 .6rem;font-size:.8rem;color:var(--muted)">
      <?php foreach ($dbMissingByType as $type => $cnt): ?>
        <span style="margin-right:.75rem"><strong style="color:var(--fg)"><?= $cnt ?>×</strong> <?= esc($type) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$latestDate): ?>
    <div class="empty" style="padding:2.5rem 1rem">
      <div class="icon">📋</div>
      <h3>No audits run yet</h3>
      <p>Run your first audit to start tracking missing orders.</p>
      <a href="?page=run" class="btn btn-sm" style="display:inline-block;margin-top:.75rem">▶ Run Audit</a>
    </div>

  <?php elseif ($latestCount === 0): ?>
    <div class="empty" style="padding:2rem 1rem">
      <div class="icon">✅</div>
      <h3>All clear from <?= esc($latestDate) ?></h3>
      <p>No missing orders in the most recent audit. Run a new one to check the latest range.</p>
      <a href="?page=run" class="btn btn-sm" style="display:inline-block;margin-top:.75rem">▶ Run Audit</a>
    </div>

  <?php else: ?>
    <div class="table-wrap" style="margin-top:0">
      <table id="tbl-dashboard-queue">
        <thead>
          <tr>
            <th>Order</th>
            <th>Type</th>
            <th>Placed</th>
            <th>Email</th>
            <th>Total</th>
            <th>Financial</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($latestReport['missing'] as $row):
            $orderNum  = $row['shopify_name'] ?: $row['order_number'] ?? '';
            $shopifyId = $row['shopify_id'] ?? '';
            $adminUrl  = $shopifyId ? $shopifyAdminBase . '/' . esc($shopifyId) : null;
            $numClean  = ltrim($orderNum, '#');
            $seen      = $orderHistory[Comparator::normalise($row['order_number'] ?? '')] ?? null;
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($orderNum) ?></a>
              <?php else: ?>
                <?= esc($orderNum) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc($numClean) ?>" title="Copy">⧉</button>
              <?php if ($seen && $seen['count'] > 1): ?>
                <span class="seen-badge" title="Seen in <?= $seen['count'] ?> reports"><?= $seen['count'] ?>×</span>
              <?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?= esc($row['order_type'] ?? '-') ?></td>
            <td class="text-sm"><?= esc(substr($row['created_at'] ?? '', 0, 10)) ?></td>
            <td class="td-email"><?= esc($row['email'] ?? '') ?></td>
            <td class="td-price"><?= formatPrice($row['total_price'] ?? null) ?></td>
            <td>
              <?php if ($row['financial_status'] ?? ''): ?>
                <span class="chip <?= financialChip($row['financial_status']) ?>"><?= esc($row['financial_status']) ?></span>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Shopify</a>
              <?php endif; ?>
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode($numClean) ?>">Spot-check</a>
              <a class="ignore-btn" href="?page=timeline&order=<?= urlencode($numClean) ?>">Timeline</a>
              <?php if ($row['email'] ?? ''): ?>
                <a class="ignore-btn" href="?page=customer&email=<?= urlencode($row['email']) ?>">Customer</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ── Bottom: History + Quick Actions ──────────────────────────────── -->
<div class="db-two-col">

  <!-- Audit History -->
  <div>
    <div class="db-section-title">
      📊 Audit History
      <?php if ($dbTotalReports > 10): ?>
        <a href="?page=trends">All trends &rarr;</a>
      <?php endif; ?>
    </div>
    <?php if (empty($dbTrendReports)): ?>
      <div class="text-sm" style="color:var(--muted);padding:.5rem 0">No reports yet.</div>
    <?php else: ?>
      <div class="db-panel">
        <?php foreach ($dbTrendReports as $r):
          $pct     = $dbMaxCount > 0 ? round($r['count'] / $dbMaxCount * 100) : 0;
          $barMod  = $r['count'] === 0 ? '' : ($r['count'] >= 5 ? 'db-history-bar--danger' : 'db-history-bar--warn');
          $isLatest = ($r === $dbTrendReports[0]);
        ?>
        <div class="db-history-row">
          <div class="db-history-date">
            <a href="?date=<?= esc($r['date']) ?>" style="color:<?= $isLatest ? 'var(--accent)' : 'var(--muted)' ?>;font-weight:<?= $isLatest ? '600' : '400' ?>">
              <?= esc($r['date']) ?>
            </a>
          </div>
          <div class="db-history-count" style="color:<?= $r['count'] === 0 ? 'var(--ok)' : ($r['count'] >= 5 ? 'var(--danger)' : 'var(--warn)') ?>">
            <?= $r['count'] ?>
          </div>
          <div class="db-history-bar-wrap">
            <div class="db-history-bar <?= $barMod ?>" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Quick Actions -->
  <div>
    <div class="db-section-title">⚡ Quick Actions</div>
    <div class="db-quick-grid">
      <a href="?page=run"          class="db-quick-btn"><span class="icon">▶</span> Run Audit</a>
      <a href="?page=addrcheck"    class="db-quick-btn"><span class="icon">📍</span> Address Scan</a>
      <a href="?page=emailcheck"   class="db-quick-btn"><span class="icon">✉</span> Email Check</a>
      <a href="?page=bundlecheck"  class="db-quick-btn"><span class="icon">🧩</span> Bundle Check</a>
      <a href="?page=partialfulfill" class="db-quick-btn"><span class="icon">⏳</span> Partial Stalls</a>
      <a href="?page=onholdstall"  class="db-quick-btn"><span class="icon">⏸</span> On-Hold Stall</a>
      <a href="?page=noteflags"    class="db-quick-btn"><span class="icon">🚩</span> Note Flags</a>
      <a href="?page=ssshipped"    class="db-quick-btn"><span class="icon">🔄</span> SS Sync Check</a>
      <a href="?page=orphans"      class="db-quick-btn"><span class="icon">👻</span> Orphan Detector</a>
      <a href="?page=zombieproducts" class="db-quick-btn"><span class="icon">🧟</span> Zombie Products</a>
    </div>

    <?php if (!empty($dbPushRecent)): ?>
      <div class="db-section-title" style="margin-top:1.5rem">
        📤 Recent Pushes
        <a href="?page=pushlog">All &rarr;</a>
      </div>
      <div class="db-panel" style="padding:.75rem 1rem">
        <?php foreach (array_slice($dbPushRecent, 0, 5) as $p): ?>
        <div class="db-history-row">
          <div class="db-history-date" style="width:auto;flex:1">
            <a href="?page=spotcheck&prefill=<?= urlencode($p['order_number'] ?? '') ?>"><?= esc($p['order_number'] ?? '-') ?></a>
          </div>
          <div style="font-size:.78rem;color:var(--muted)"><?= esc(substr($p['pushed_at'] ?? '', 0, 10)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
