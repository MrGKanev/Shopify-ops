<?= topbar('Return / RMA Tracker', 'Item-level return details with per-SKU return rate summary') ?>

<?= featureInfoStart('returns', 'Return / RMA Tracker') ?>
  <p><strong>Return / RMA Tracker</strong> fetches all refunded and partially-refunded Shopify orders in the selected date range and shows the returned items from each refund.</p>
  <ul>
    <li>Each row represents one refund event, with the items returned and the refund amount.</li>
    <li>The <strong>Reason</strong> column shows the note attached to the refund (if any).</li>
    <li>The <strong>SKU Return Summary</strong> at the bottom shows total units returned and revenue refunded per SKU across all refunds in the date range.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan returns</h2>
  <div class="hint">Fetches refunded Shopify orders and shows item-level return details with a per-SKU summary.</div>

  <?php if ($rtError): ?>
    <div class="error-msg mb-3"><?= esc($rtError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_returns">
    <?php dateRangePartial('rt', $rtStart, $rtEnd) ?>
  </form>

  <?php if ($rtResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= count($rtResult['rows']) ?></strong> refund<?= count($rtResult['rows']) !== 1 ? 's' : '' ?>
        from <strong><?= $rtResult['scanned'] ?></strong> order<?= $rtResult['scanned'] !== 1 ? 's' : '' ?>
        &nbsp;(<?= esc($rtResult['start']) ?> &rarr; <?= esc($rtResult['end']) ?>)
      </span>
      <?php if (!empty($rtResult['sku_stat'])): ?>
        <span class="source-badge"><?= count($rtResult['sku_stat']) ?> SKU<?= count($rtResult['sku_stat']) !== 1 ? 's' : '' ?> returned</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($rtResult !== null): ?>
  <?php if (empty($rtResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No returns found</h3>
        <p>No refunded orders with return line items in this date range.</p>
      </div>
    </div>
  <?php else: ?>

    <div class="table-wrap">
      <?= tableWrapHeader(
            $rtResult['rows'],
            'tbl-returns',
            'Refund Events',
            'returns',
            $rtResult['start'],
            'refund',
            'Filter by order # or reason...'
          ) ?>
      <?= searchInput('tbl-returns', 'Filter by order # or reason...') ?>
      <table id="tbl-returns">
        <thead>
          <tr>
            <th>Order</th>
            <th>Refund Date</th>
            <th>Reason / Note</th>
            <th>Items Returned</th>
            <th>Refund Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rtResult['rows'] as $row): ?>
          <?php
            $shopifyUrl = $row['shopify_id']
              ? $shopifyAdminBase . '/' . $row['shopify_id']
              : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $shopifyUrl) ?>
            <td><?= esc($row['refund_date']) ?></td>
            <td class="text-sm text-muted">
              <?= $row['reason'] !== '' ? esc($row['reason']) : '<span class="text-muted">—</span>' ?>
            </td>
            <td>
              <?php foreach ($row['items'] as $item): ?>
                <div class="text-sm">
                  <span class="font-semibold"><?= (int)$item['quantity'] ?>&times;</span>
                  <?= esc($item['name']) ?>
                  <?php if ($item['sku'] !== ''): ?>
                    <span class="text-muted font-mono">(<?= esc($item['sku']) ?>)</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if (empty($row['items'])): ?>
                <span class="text-muted">No line items</span>
              <?php endif; ?>
            </td>
            <td class="font-semibold">
              <?= formatPrice($row['refund_total']) ?>
            </td>
            <?= actionLinks([
              'orderNum'   => $row['order_number'],
              'shopifyUrl' => $shopifyUrl,
              'spotcheck'  => true,
              'timeline'   => true,
            ]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($rtResult['sku_stat'])): ?>
    <div class="table-wrap" style="margin-top:1.5rem">
      <div class="table-header">
        <h2>Return Rate by SKU</h2>
        <div class="flex items-center gap-2">
          <span><?= count($rtResult['sku_stat']) ?> SKU<?= count($rtResult['sku_stat']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-returns-sku"
                  data-csv-filename="returns-by-sku-<?= esc($rtResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-returns-sku">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Units Returned</th>
            <th>Return Events</th>
            <th>Revenue Refunded</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rtResult['sku_stat'] as $stat): ?>
          <tr>
            <td class="font-mono text-sm"><?= esc($stat['sku']) ?></td>
            <td><?= (int)$stat['units'] ?></td>
            <td><?= (int)$stat['orders'] ?></td>
            <td><?= formatPrice($stat['revenue']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>
