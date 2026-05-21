<?php
/**
 * Partial: Missing Orders table.
 *
 * Required variables (set before require):
 *   $partialMissing          array   - missing order rows
 *   $partialIgnoredOrders    array   - currently ignored orders
 *   $partialShopifyAdminBase string  - Shopify admin URL base
 *   $partialContext          string  - page context ('reports', 'run', …)
 *   $partialContextVal       string  - context value (date, etc.)
 *   $partialOrderHistory     array   - seen-count history
 */

$missing          = $partialMissing;
$ignoredOrders    = $partialIgnoredOrders;
$shopifyAdminBase = $partialShopifyAdminBase;
$context          = $partialContext;
$contextVal       = $partialContextVal;
$orderHistory     = $partialOrderHistory;

$count   = count($missing);
$tableId = 'tbl-' . substr(md5($context . $contextVal), 0, 6);
$formId  = 'bulk-' . substr(md5($context . $contextVal), 0, 6);
?>
<?php
$allTypes = [];
foreach ($missing as $r) { $t = classifyOrder($r); if ($t) $allTypes[$t] = true; }
ksort($allTypes);
?>
<div class="search-wrap">
  <input class="js-search" data-target="<?= esc($tableId) ?>"
         placeholder="Filter by order #, email, status…" type="search">
  <?php if (count($allTypes) > 1): ?>
  <select class="js-type-filter" data-target="<?= esc($tableId) ?>">
    <option value="">All types</option>
    <?php foreach (array_keys($allTypes) as $t): ?>
      <option value="<?= esc($t) ?>"><?= esc($t) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</div>

<?php if ($count > 0): ?>
<form id="<?= esc($formId) ?>" method="post" class="bulk-form">
  <input type="hidden" name="action" value="bulk_ignore_orders">
  <input type="hidden" name="redirect_page" value="<?= esc($context) ?>">
  <input type="hidden" name="redirect_date" value="<?= esc($contextVal) ?>">
  <div class="bulk-bar" id="bar-<?= esc($formId) ?>">
    <span class="bulk-count" id="cnt-<?= esc($formId) ?>">0 selected</span>
    <input type="text" name="reason" placeholder="Reason (optional)" class="bulk-reason">
    <button class="btn btn-sm btn-danger" type="submit">Ignore selected</button>
    <button class="btn btn-sm btn-ghost" type="button"
            onclick="document.querySelectorAll('#<?= esc($tableId) ?> .js-row-check').forEach(c=>c.checked=false);updateBulkBar('<?= esc($formId) ?>')">
      Clear
    </button>
  </div>
<?php endif; ?>

<div class="table-wrap">
  <div class="table-header">
    <h2>Missing Orders</h2>
    <span><?= $count ?> order<?= $count !== 1 ? 's' : '' ?></span>
  </div>

  <?php if ($count === 0): ?>
    <div class="empty">
      <div class="icon">✅</div>
      <h3>All clear!</h3>
      <p>Every paid Shopify order was found in ShipStation.</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th class="col-check">
            <input type="checkbox" class="js-select-all" data-target="<?= esc($tableId) ?>"
                   data-bar="<?= esc($formId) ?>" title="Select all">
          </th>
          <th>Order #</th>
          <th>Seen</th>
          <th>Created</th>
          <th>Total</th>
          <th>Type</th>
          <th>Financial</th>
          <th>Email</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody id="<?= esc($tableId) ?>">
        <?php foreach ($missing as $row):
          $num       = (string) ($row['order_number'] ?? $row['name'] ?? '?');
          $shopifyId = $row['id'] ?? $row['shopify_id'] ?? '';
          $financial = strtolower($row['financial_status'] ?? '');
          $chipClass = match($financial) {
            'paid'           => 'chip-paid',
            'partially_paid' => 'chip-partial',
            'unpaid'         => 'chip-unpaid',
            default          => 'chip-unknown',
          };
          $totalPrice = isset($row['total_price']) && $row['total_price'] !== ''
            ? '$' . number_format((float) $row['total_price'], 2)
            : '-';
          $orderType   = classifyOrder($row);
          $typeClass   = 'chip-type-' . (crc32($orderType) % 6 + 6) % 6;
          $adminUrl    = $shopifyId ? $shopifyAdminBase . '/' . esc($shopifyId) : null;
          $normNum     = preg_replace('/\D/', '', ltrim(trim($num), '#'));
          $ssSearchUrl = 'https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch='
                       . urlencode(ltrim($num, '#'));
          $seenCount   = $orderHistory[$normNum]['count'] ?? 1;
          $isRepeat    = $seenCount >= 2;
        ?>
        <tr class="<?= $isRepeat ? 'row-repeat' : '' ?>">
          <td>
            <input type="checkbox" class="js-row-check" name="order_numbers[]"
                   value="<?= esc($num) ?>" data-bar="<?= esc($formId) ?>"
                   onchange="updateBulkBar('<?= esc($formId) ?>')">
          </td>
          <td>
            <?php if ($adminUrl): ?>
              <a class="order-num" href="<?= $adminUrl ?>" target="_blank" rel="noopener">#<?= esc($num) ?></a>
            <?php else: ?>
              <span class="order-num">#<?= esc($num) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($seenCount >= 3): ?>
              <span class="seen-badge seen-hot" title="Appeared in <?= $seenCount ?> reports"><?= $seenCount ?>×</span>
            <?php elseif ($seenCount === 2): ?>
              <span class="seen-badge seen-warn" title="Appeared in 2 reports">2×</span>
            <?php else: ?>
              <span class="seen-1x">1×</span>
            <?php endif; ?>
          </td>
          <td><?= esc(substr($row['created_at'] ?? '', 0, 10)) ?></td>
          <td class="td-price"><?= $totalPrice ?></td>
          <td><span class="chip <?= $typeClass ?>"><?= esc($orderType) ?></span></td>
          <td><span class="chip <?= $chipClass ?>"><?= esc($row['financial_status'] ?? '-') ?></span></td>
          <td class="td-email"><?= esc($row['email'] ?? '-') ?></td>
          <td class="td-actions">
            <a class="ignore-btn" href="<?= esc($ssSearchUrl) ?>" target="_blank" rel="noopener"
               class="action-link">Search SS</a>
            <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($num, '#')) ?>"
               class="action-link">Re-check</a>
            <?php if ($shopifyId): ?>
            <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener"
               class="action-link">Shopify</a>
            <button class="ignore-btn action-link" type="button"
                    onclick="previewPush(<?= esc(json_encode($shopifyId)) ?>, <?= esc(json_encode('#' . $num)) ?>)">
              Preview
            </button>
            <form method="post" class="js-push-form inline">
              <input type="hidden" name="action" value="push_to_shipstation">
              <input type="hidden" name="shopify_id" value="<?= esc($shopifyId) ?>">
              <input type="hidden" name="redirect_page" value="<?= esc($context) ?>">
              <input type="hidden" name="redirect_date" value="<?= esc($contextVal) ?>">
              <button class="ignore-btn btn-push" type="submit"
                      onclick="this.textContent='Pushing…';this.disabled=true;this.form.submit()">
                Push to SS
              </button>
            </form>
            <?php endif; ?>
            <button class="ignore-btn js-ignore-toggle" data-order="<?= esc($normNum) ?>">Ignore</button>
            <div id="ignore-form-<?= esc($normNum) ?>" class="ignore-form-row" style="display:none">
              <form method="post" class="contents">
                <input type="hidden" name="action" value="ignore_order">
                <input type="hidden" name="order_number" value="<?= esc($num) ?>">
                <input type="hidden" name="redirect_page" value="<?= esc($context) ?>">
                <input type="hidden" name="redirect_date" value="<?= esc($contextVal) ?>">
                <input type="text" name="reason" placeholder="Reason (optional)" class="input-reason">
                <button class="btn btn-sm btn-danger" type="submit">Confirm</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($count > 0): ?>
</form>
<?php endif; ?>
