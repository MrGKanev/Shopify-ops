<?= topbar('Duplicate Detector', 'Find orders from the same customer with the same total placed within 10 minutes') ?>

<?= featureInfoStart('dupes', 'Duplicate Detector') ?>
  <p><strong>Duplicate Detector</strong> scans Shopify orders in the selected date range and finds pairs where the same customer (email) placed two orders with an identical total within <strong>10 minutes</strong> of each other.</p>
  <p>Typical cause: the customer clicked "Place order" twice, or the page reloaded after payment. This tool helps catch double-charges before both packages are shipped.</p>
  <ul>
    <li>Matching is done by <strong>email + order total</strong> - not by line items.</li>
    <li>Results show both orders side by side with financial status and the time gap between them.</li>
    <li>For large date ranges results may be truncated - narrow the period if needed.</li>
  </ul>

<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan for duplicates</h2>
  <div class="hint">Searches Shopify orders in the date range. Flags pairs with identical email + total placed within 10 minutes of each other.</div>

  <?php if ($dupesError): ?>
    <div class="error-msg mb-3"><?= esc($dupesError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="find_dupes">
    <?php
$partialStartName = 'dupes_start'; $partialStartVal = $dupesStart;
$partialEndName   = 'dupes_end';   $partialEndVal   = $dupesEnd;
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($dupesResult !== null): ?>
    <div class="duration-note mt-4 mb-0">
      Scanned <strong><?= $dupesResult['scanned'] ?></strong> orders
      (<?= esc($dupesResult['start']) ?> → <?= esc($dupesResult['end']) ?>)
      &mdash; <strong><?= count($dupesResult['pairs']) ?></strong> duplicate pair<?= count($dupesResult['pairs']) !== 1 ? 's' : '' ?> found
      <?php if ($dupesResult['truncated']): ?>
        <span class="source-badge cached">results truncated - narrow the date range</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($dupesResult !== null): ?>
  <?php if (empty($dupesResult['pairs'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No duplicates found</h3>
        <p>No orders with the same email and total within 10 minutes in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Duplicate Pairs</h2>
        <div class="flex items-center gap-2">
          <span><?= count($dupesResult['pairs']) ?> pair<?= count($dupesResult['pairs']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-dupes" data-csv-filename="duplicate-pairs.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-dupes">
        <thead>
          <tr>
            <th>#</th>
            <th>Order A</th>
            <th>Order B</th>
            <th>Email</th>
            <th>Total</th>
            <th>Gap</th>
            <th>Status A / B</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dupesResult['pairs'] as $i => [$a, $b]):
            $idA  = $a['legacyResourceId'] ?? '';
            $idB  = $b['legacyResourceId'] ?? '';
            $urlA = $idA ? $shopifyAdminBase . '/' . $idA : null;
            $urlB = $idB ? $shopifyAdminBase . '/' . $idB : null;
            $gap  = abs(strtotime($a['createdAt']) - strtotime($b['createdAt']));
            $gapFmt = $gap >= 60 ? floor($gap / 60) . 'm ' . ($gap % 60) . 's' : $gap . 's';
            $amount = $a['totalPriceSet']['shopMoney']['amount'] ?? '0';
            $currency = $a['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD';
          ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <?php if ($urlA): ?>
                <a href="<?= esc($urlA) ?>" target="_blank" rel="noopener" class="spot-match-tag spot-match-tag-sh"><?= esc($a['name']) ?></a>
              <?php else: ?>
                <?= esc($a['name']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc(ltrim($a['name'], '#')) ?>" title="Copy">⧉</button>
              <div class="text-xs text-muted"><?= substr($a['createdAt'], 0, 16) ?></div>
            </td>
            <td>
              <?php if ($urlB): ?>
                <a href="<?= esc($urlB) ?>" target="_blank" rel="noopener" class="spot-match-tag spot-match-tag-sh"><?= esc($b['name']) ?></a>
              <?php else: ?>
                <?= esc($b['name']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc(ltrim($b['name'], '#')) ?>" title="Copy">⧉</button>
              <div class="text-xs text-muted"><?= substr($b['createdAt'], 0, 16) ?></div>
            </td>
            <td><?= esc($a['email'] ?? '') ?></td>
            <td><?= esc($currency) ?> <?= number_format((float)$amount, 2) ?></td>
            <td><span class="source-badge cached"><?= esc($gapFmt) ?></span></td>
            <td>
              <span class="source-badge"><?= esc($a['displayFinancialStatus'] ?? '-') ?></span>
              <span class="source-badge"><?= esc($b['displayFinancialStatus'] ?? '-') ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
