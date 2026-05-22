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
    <?php
      $sparkPoints = $historySlice; // oldest first
      $n     = count($sparkPoints);
      /* SVG coordinate space — only used for line/fill, not dots */
      $svgW  = 1000; $svgH = 200;
      $padL  = 0;    $padR = 0; $padT = 10; $padB = 0;
      $plotW = $svgW; $plotH = $svgH - $padT - $padB;

      /* % positions for HTML overlay dots */
      $ptPct = [];
      foreach ($sparkPoints as $i => $r) {
        $xPct = $n > 1 ? ($i / ($n - 1)) * 100 : 50;
        $yPct = $maxCount > 0 ? (1 - $r['count'] / $maxCount) * 100 : 100;
        $ptPct[] = [$xPct, $yPct, $r];
      }

      /* SVG polyline using same % mapping */
      $toSvg   = fn($xp,$yp) => round($xp/100*$svgW,1).','.round($padT+$yp/100*$plotH,1);
      $polyPts = implode(' ', array_map(fn($p) => $toSvg($p[0],$p[1]), $ptPct));
      $fillPath = 'M0,'.($svgH);
      foreach ($ptPct as $p) $fillPath .= ' L'.$toSvg($p[0],$p[1]);
      $fillPath .= ' L'.$svgW.','.$svgH.' Z';

      $labelStep = max(1, (int)ceil($n / 8));
    ?>
    <div class="sparkline-wrap">
      <div class="sparkline-header">
        <span class="sparkline-title">History</span>
        <span class="sparkline-peak"><?= $maxCount ?> peak missing</span>
      </div>
      <div class="sparkline-body">
        <!-- y-axis -->
        <div class="spk-yaxis">
          <span><?= $maxCount ?></span>
          <span><?= round($maxCount / 2) ?></span>
          <span>0</span>
        </div>
        <!-- chart area -->
        <div class="spk-area">
          <!-- SVG: line + fill only, stretches freely -->
          <svg class="spk-svg" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>"
               preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="spk-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%"   stop-color="var(--warn)" stop-opacity=".2"/>
                <stop offset="100%" stop-color="var(--warn)" stop-opacity="0"/>
              </linearGradient>
            </defs>
            <path d="<?= $fillPath ?>" fill="url(#spk-fill)"/>
            <polyline points="<?= $polyPts ?>" class="spk-line" fill="none"/>
          </svg>

          <!-- HTML dots — perfectly circular regardless of SVG stretch -->
          <?php foreach ($ptPct as $i => [$xPct, $yPct, $r]): ?>
            <?php $sel = $r['date'] === $selectedDate; ?>
            <a href="?date=<?= esc($r['date']) ?>"
               class="spk-dot<?= $sel ? ' spk-dot-selected' : '' ?>"
               style="left:<?= round($xPct,2) ?>%;top:<?= round($yPct,2) ?>%;
                      background:<?= $r['count'] === 0 ? 'var(--ok)' : 'var(--warn)' ?>"
               title="<?= esc($r['date']) ?>: <?= $r['count'] ?> missing"></a>
          <?php endforeach; ?>

          <!-- x-axis labels -->
          <div class="spk-xlabels">
            <?php foreach ($ptPct as $i => [$xPct, , $r]): ?>
              <?php if ($i % $labelStep === 0 || $i === $n - 1): ?>
                <a href="?date=<?= esc($r['date']) ?>" class="spk-xlabel<?= $r['date'] === $selectedDate ? ' spk-xlabel-active' : '' ?>" style="left:<?= round($xPct,2) ?>%">
                  <?= substr($r['date'], 5) ?>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
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
