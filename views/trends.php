<?php
// ── Aggregate stats across all reports ───────────────────────────────────────

$totalReports  = count($reports);
$totalMissing  = array_sum(array_column($reports, 'count'));
$avgMissing    = $totalReports > 0 ? round($totalMissing / $totalReports, 1) : 0;
$worstReport   = $totalReports > 0 ? max(array_column($reports, 'count')) : 0;
$clearReports  = count(array_filter($reports, fn($r) => $r['count'] === 0));

// Repeat offenders: order numbers appearing in 2+ reports, sorted by count desc
$repeatOffenders = array_filter($orderHistory, fn($h) => $h['count'] >= 2);
uasort($repeatOffenders, fn($a, $b) => $b['count'] <=> $a['count']);
$repeatOffenders = array_slice($repeatOffenders, 0, 20, true);
?>

<div class="topbar">
  <div>
    <h1>Trends</h1>
    <div class="meta">Aggregated stats across all <?= $totalReports ?> report<?= $totalReports !== 1 ? 's' : '' ?></div>
  </div>
</div>

<?= featureInfoStart('trends', 'Trends') ?>
  <p><strong>Trends</strong> aggregates data across all saved audit reports to give you a historical view of how your ShipStation sync health has changed over time.</p>
  <ul>
    <li><strong>Summary stats</strong> - total missing orders across all runs, average per report, worst single day, and number of all-clear reports.</li>
    <li><strong>Repeat offenders</strong> - orders that have appeared as missing in 2 or more reports. These are the highest-priority items to investigate, as they have been missed repeatedly.</li>
  </ul>
  <p>Data is derived entirely from the local report CSV files - no API calls are made on this page.</p>
<?= featureInfoEnd() ?>

<?php if ($totalReports === 0): ?>
  <div class="no-reports">
    <div class="icon">📊</div>
    <h2>No data yet</h2>
    <p>Run your first audit to start seeing trends.</p>
    <a class="btn mt-4" href="?page=run">Run first audit</a>
  </div>
<?php else: ?>

<div class="stats mb-8">
  <div class="stat-card">
    <div class="label">Reports</div>
    <div class="value accent"><?= $totalReports ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Avg missing / report</div>
    <div class="value <?= $avgMissing > 0 ? 'warn' : 'ok' ?>"><?= $avgMissing ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Worst report</div>
    <div class="value <?= $worstReport > 0 ? 'warn' : 'ok' ?>"><?= $worstReport ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Clear days</div>
    <div class="value ok"><?= $clearReports ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Unique missing ever</div>
    <div class="value accent"><?= count($orderHistory) ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Repeat offenders</div>
    <div class="value <?= count($repeatOffenders) > 0 ? 'warn' : 'ok' ?>"><?= count($repeatOffenders) ?></div>
  </div>
</div>

<?php if ($totalReports > 1):
  $historySlice = array_reverse($reports);
  $maxCount     = max(1, max(array_column($historySlice, 'count')));
?>
<div class="text-xs font-bold uppercase mb-2 text-muted tracking-[.07em]">Missing orders over time</div>
<div class="history mb-8 h-[120px]">
  <?php foreach ($historySlice as $r):
    $pct   = max(6, round(($r['count'] / $maxCount) * 100));
    $color = $r['count'] === 0 ? 'var(--ok)' : 'var(--warn)';
  ?>
    <a href="?date=<?= esc($r['date']) ?>"
       class="flex-1 block no-underline min-w-0"
       title="<?= esc($r['date']) ?>: <?= $r['count'] ?> missing">
      <div class="history-bar" style="height:<?= $pct ?>px;background:<?= $color ?>"></div>
      <?php if ($totalReports <= 20): ?>
        <div class="history-label"><?= substr($r['date'], 5) ?></div>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($repeatOffenders)): ?>
<div class="table-wrap mb-8">
  <div class="table-header !flex-col !items-start gap-2">
    <div class="flex items-center justify-between w-full">
      <h2>Repeat Offenders</h2>
      <span><?= count($repeatOffenders) ?> order<?= count($repeatOffenders) !== 1 ? 's' : '' ?></span>
    </div>
    <p class="text-xs text-muted m-0">
      Orders that appeared as missing in 2 or more reports. These are prime candidates to investigate or bulk-ignore.
    </p>
  </div>

  <form method="post">
    <input type="hidden" name="action" value="bulk_ignore_orders">
    <input type="hidden" name="redirect_page" value="trends">

    <div class="bulk-bar !flex" id="bar-repeat">
      <span class="bulk-count" id="cnt-repeat">Select to bulk-ignore</span>
      <input type="text" name="reason" placeholder="Reason (optional)" class="bulk-reason">
      <button class="btn btn-sm btn-danger" type="submit">Ignore selected</button>
    </div>

    <table>
      <thead>
        <tr>
          <th class="col-check">
            <input type="checkbox" class="js-select-all" data-target="repeat-tbody" data-bar="repeat" title="Select all">
          </th>
          <th>Order #</th>
          <th>Seen in reports</th>
          <th>First seen</th>
          <th>Last seen</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="repeat-tbody">
        <?php foreach ($repeatOffenders as $normNum => $h):
          $isIgnored  = isset($ignoredOrders[$normNum]);
          $displayNum = '#' . $normNum;
          $ssSearch   = 'https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch=' . urlencode($normNum);
        ?>
        <tr class="<?= $isIgnored ? '' : ($h['count'] >= 3 ? 'row-repeat' : '') ?>">
          <td>
            <?php if (!$isIgnored): ?>
            <input type="checkbox" class="js-row-check" name="order_numbers[]"
                   value="<?= esc($normNum) ?>" data-bar="repeat"
                   onchange="updateBulkBar('repeat')">
            <?php endif; ?>
          </td>
          <td>
            <a class="order-num" href="<?= esc($ssSearch) ?>" target="_blank" rel="noopener">
              <?= esc($displayNum) ?>
            </a>
          </td>
          <td>
            <?php if ($h['count'] >= 3): ?>
              <span class="seen-badge seen-hot"><?= $h['count'] ?>×</span>
            <?php else: ?>
              <span class="seen-badge seen-warn"><?= $h['count'] ?>×</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= esc($h['first']) ?></td>
          <td class="text-muted"><?= esc($h['last']) ?></td>
          <td>
            <?php if ($isIgnored): ?>
              <span class="chip chip-unknown">Ignored</span>
            <?php else: ?>
              <span class="chip chip-unpaid">Active</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>
</div>
<?php else: ?>
  <div class="no-reports p-8">
    <div class="icon">✅</div>
    <h2 class="text-ok">No repeat offenders</h2>
    <p>No order has appeared as missing in more than one report.</p>
  </div>
<?php endif; ?>

<?php endif; ?>
