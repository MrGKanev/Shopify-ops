<?= topbar('Global Search', 'Search order numbers across reports, push log, and ignored list') ?>

<?php if ($gsResults === null): ?>
  <div class="empty">
    <div class="icon">🔍</div>
    <h3>Search across all local data</h3>
    <p>Type an order number in the sidebar search box to look it up across audit reports, push log, and ignored orders at once.</p>
    <p><a href="?page=spotcheck" class="text-accent">Use Spot-check</a> for a live lookup in ShipStation &amp; Shopify.</p>
  </div>
<?php else:
  $q         = esc($gsResults['query']);
  $repRows   = $gsResults['reports'];
  $pushRows  = $gsResults['push'];
  $ignRows   = $gsResults['ignored'];
  $total     = count($repRows) + count($pushRows) + count($ignRows);
?>

<div class="flex items-center gap-3 mb-6 flex-wrap">
  <span class="text-sm text-muted">Results for <strong class="text-ink">"<?= $q ?>"</strong></span>
  <?php if ($total === 0): ?>
    <span class="badge badge-ok">Nothing found locally</span>
  <?php else: ?>
    <span class="badge badge-warn"><?= $total ?> match<?= $total !== 1 ? 'es' : '' ?></span>
  <?php endif; ?>
  <a href="?page=spotcheck&prefill=<?= urlencode($gsResults['query']) ?>" class="btn btn-ghost btn-sm" style="margin-left:auto">
    Live lookup in Spot-check →
  </a>
</div>

<?php if ($total === 0): ?>
  <div class="empty">
    <div class="icon">🕵️</div>
    <h3>Not found locally</h3>
    <p>No audit reports, push log entries, or ignored orders match <strong><?= $q ?></strong>.</p>
    <p><a href="?page=spotcheck&prefill=<?= urlencode($gsResults['query']) ?>" class="text-accent">Run a live Spot-check →</a></p>
  </div>
<?php else: ?>

  <?php if (!empty($repRows)): ?>
    <div class="table-wrap mb-6">
      <div class="table-header">
        <h2>Audit reports</h2>
        <span><?= count($repRows) ?> match<?= count($repRows) !== 1 ? 'es' : '' ?></span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Order #</th>
            <th>Times seen</th>
            <th>First report</th>
            <th>Last report</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repRows as $r): ?>
          <tr>
            <td class="order-num"><?= esc($r['number']) ?></td>
            <td><?= (int) $r['count'] ?></td>
            <td class="text-sm"><?= esc($r['first']) ?></td>
            <td class="text-sm"><?= esc($r['last']) ?></td>
            <td>
              <a class="ignore-btn" href="?date=<?= urlencode($r['last']) ?>">View report</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($pushRows)): ?>
    <div class="table-wrap mb-6">
      <div class="table-header">
        <h2>Push log</h2>
        <span><?= count($pushRows) ?> match<?= count($pushRows) !== 1 ? 'es' : '' ?></span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Order #</th>
            <th>Shopify ID</th>
            <th>SS Order ID</th>
            <th>Pushed at</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pushRows as $entry):
            $orderNum  = $entry['order_number'] ?? '-';
            $shopifyId = $entry['shopify_id']   ?? '';
            $ssId      = $entry['ss_order_id']  ?? '-';
            $pushedAt  = $entry['pushed_at']    ?? '-';
            $adminUrl  = $shopifyId
              ? 'https://' . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com") . '/admin/orders/' . esc($shopifyId)
              : null;
          ?>
          <tr>
            <td class="order-num"><?= esc($orderNum) ?></td>
            <td class="font-mono text-[.8rem] text-muted">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($shopifyId) ?></a>
              <?php else: ?>
                <?= esc($shopifyId) ?>
              <?php endif; ?>
            </td>
            <td class="font-mono text-[.8rem] text-muted"><?= esc($ssId) ?></td>
            <td class="text-sm"><?= esc($pushedAt) ?></td>
            <td>
              <a class="ignore-btn"
                 href="https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch=<?= urlencode(ltrim($orderNum, '#')) ?>"
                 target="_blank" rel="noopener">View in SS</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($ignRows)): ?>
    <div class="table-wrap mb-6">
      <div class="table-header">
        <h2>Ignored orders</h2>
        <span><?= count($ignRows) ?> match<?= count($ignRows) !== 1 ? 'es' : '' ?></span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Order #</th>
            <th>Reason</th>
            <th>Ignored at</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ignRows as $entry): ?>
          <tr>
            <td class="order-num"><?= esc($entry['number']) ?></td>
            <td class="text-sm"><?= esc($entry['reason'] ?? '—') ?></td>
            <td class="text-sm"><?= esc($entry['ignored_at'] ?? '—') ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="unignore_order">
                <input type="hidden" name="order_number" value="<?= esc($entry['number']) ?>">
                <button class="ignore-btn btn-danger" type="submit">Unignore</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>
<?php endif; ?>
