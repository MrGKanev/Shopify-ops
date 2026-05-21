<div class="topbar">
  <div>
    <h1>Order Audit</h1>
    <?php if ($selectedReport): ?>
      <div class="meta"><?= esc($selectedReport['date']) ?></div>
    <?php endif; ?>
  </div>
  <?php if ($selectedReport): ?>
    <?php $count = $selectedReport['count']; ?>
    <div class="flex items-center gap-3">
      <span class="badge text-[.85rem] py-1 px-3 <?= $count > 0 ? 'badge-warn' : 'badge-ok' ?>">
        <?= $count ?> missing
      </span>
      <a class="btn btn-sm btn-ghost"
         href="?action=download&date=<?= esc($selectedReport['date']) ?>" download>Download CSV</a>
      <a class="btn btn-sm btn-ghost"
         href="?page=run&start=<?= esc($selectedReport['date']) ?>&end=<?= esc($selectedReport['date']) ?>">
        Re-audit
      </a>
    </div>
  <?php endif; ?>
</div>

<?php if (empty($reports)): ?>

  <div class="no-reports">
    <div class="icon">📭</div>
    <h2>No reports yet</h2>
    <p class="mb-5">No audit reports found. Run your first audit to see which Shopify orders are missing in ShipStation.</p>
    <a class="btn" href="?page=run">Run first audit</a>
  </div>

<?php elseif ($selectedReport): ?>
  <?php $missing = $selectedReport['missing']; ?>

  <?php if (count($reports) > 1): ?>
    <?php
      $historySlice = array_slice(array_reverse($reports), 0, 30);
      $maxCount = max(1, max(array_column($historySlice, 'count')));
    ?>
    <div class="text-xs font-bold uppercase mb-2 text-muted tracking-[.07em]">History</div>
    <div class="history history-compact">
      <?php foreach ($historySlice as $r): ?>
        <?php
          $pct    = max(6, round(($r['count'] / $maxCount) * 56));
          $color  = $r['count'] === 0 ? 'var(--ok)' : 'var(--warn)';
          $active = $r['date'] === $selectedDate ? 'selected' : '';
        ?>
        <a href="?date=<?= esc($r['date']) ?>" class="flex-1 block no-underline">
          <div class="history-bar <?= $active ?>"
               style="height:<?= $pct ?>px;background:<?= $color ?>"
               title="<?= esc($r['date']) ?>: <?= $r['count'] ?> missing"></div>
          <div class="history-label"><?= substr($r['date'], 5) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= pushFlashBanner() ?>
  <?php
    $partialMissing          = $missing;
    $partialIgnoredOrders    = $ignoredOrders;
    $partialShopifyAdminBase = $shopifyAdminBase;
    $partialContext          = 'reports';
    $partialContextVal       = $selectedDate ?? '';
    $partialOrderHistory     = $orderHistory;
    require __DIR__ . '/partials/missing-table.php';
  ?>

<?php endif; ?>
