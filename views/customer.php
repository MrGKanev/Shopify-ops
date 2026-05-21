<div class="topbar">
  <div>
    <h1>Customer Lookup</h1>
    <div class="meta">Full order history for a customer by email</div>
  </div>
</div>

<div class="feature-info" data-info-key="customer">
  <button class="feature-info-toggle" aria-expanded="false"><svg width="12" height="12" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> About: Customer Lookup</button>
  <div class="feature-info-body">
  <p><strong>Customer Lookup</strong> shows the complete order history for a customer by email address - every Shopify order they have ever placed, regardless of date.</p>
  <p>Useful when a customer contacts support and you need a quick overview: how many orders they have, lifetime spend, what tags have been applied, and whether any orders were cancelled.</p>
  <ul>
    <li>The summary card shows <strong>order count, total spent, paid orders, and cancelled orders</strong>.</li>
    <li>The tag cloud aggregates all tags across all orders, sorted by frequency.</li>
    <li>Each order row is expandable - click it to load <strong>full details</strong>: line items, shipping address, shipping method, discounts, and financial summary.</li>
    <li>Every order links directly to Spot-check for a live ShipStation cross-reference.</li>
  </ul>

  </div>
</div>

<div class="run-form">
  <h2>Search by email</h2>
  <div class="hint">Returns all Shopify orders placed with this email address.</div>

  <?php if ($customerError): ?>
    <div class="error-msg mb-3"><?= esc($customerError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="customer_lookup">
    <div class="date-row">
      <div class="field date-row-wide">
        <label>Email</label>
        <input type="email" name="customer_email" value="<?= esc($customerEmail) ?>"
               placeholder="customer@example.com" autofocus>
      </div>
      <button class="btn btn-submit-end" type="submit">Look up</button>
    </div>
  </form>
</div>

<?php if ($customerResult !== null): ?>
  <?php
    $orders   = $customerResult['orders'];
    $customer = $customerResult['customer'];
    $email    = $customerResult['email'];
    $spent    = $customerResult['totalSpent'];
    $currency = $customerResult['currency'];
    $truncated = $customerResult['truncated'];

    $firstName = $customer['firstName'] ?? '';
    $lastName  = $customer['lastName']  ?? '';
    $fullName  = trim($firstName . ' ' . $lastName) ?: null;

    $cancelledCount  = count(array_filter($orders, fn($o) => !empty($o['cancelledAt'])));
    $paidCount       = count(array_filter($orders, fn($o) => ($o['displayFinancialStatus'] ?? '') === 'PAID'));
    $allTags         = [];
    foreach ($orders as $o) {
      foreach ((array)($o['tags'] ?? []) as $t) {
        if ($t !== '') $allTags[$t] = ($allTags[$t] ?? 0) + 1;
      }
    }
    arsort($allTags);
  ?>

  <?php if (empty($orders)): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">🔍</div>
        <h3>No orders found</h3>
        <p>No Shopify orders found for <strong><?= esc($email) ?></strong>.</p>
      </div>
    </div>
  <?php else: ?>

    <!-- Customer summary card -->
    <div class="customer-card">
      <div class="customer-card-main">
        <div class="customer-avatar"><?= esc(mb_strtoupper(mb_substr($fullName ?: $email, 0, 1))) ?></div>
        <div>
          <?php if ($fullName): ?>
            <div class="customer-name"><?= esc($fullName) ?></div>
          <?php endif; ?>
          <div class="customer-email"><?= esc($email) ?>
            <?php if (!empty($customer['verifiedEmail'])): ?>
              <span class="source-badge live" style="font-size:.7rem;padding:.1rem .4rem;">verified</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($customer['createdAt'])): ?>
            <div class="text-xs text-muted mt-1">Customer since <?= esc(substr($customer['createdAt'], 0, 10)) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="customer-stats">
        <div class="customer-stat">
          <div class="customer-stat-val"><?= count($orders) ?><?= $truncated ? '+' : '' ?></div>
          <div class="customer-stat-lbl">Orders</div>
        </div>
        <div class="customer-stat">
          <div class="customer-stat-val"><?= $currency ?> <?= number_format($spent, 2) ?></div>
          <div class="customer-stat-lbl">Total spent</div>
        </div>
        <div class="customer-stat">
          <div class="customer-stat-val"><?= $paidCount ?></div>
          <div class="customer-stat-lbl">Paid</div>
        </div>
        <?php if ($cancelledCount > 0): ?>
        <div class="customer-stat">
          <div class="customer-stat-val" style="color:var(--danger)"><?= $cancelledCount ?></div>
          <div class="customer-stat-lbl">Cancelled</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($allTags)): ?>
    <div class="table-wrap mb-0" style="padding:.75rem 1rem;">
      <div class="text-xs font-bold uppercase mb-2 text-muted tracking-[.07em]">Tags across all orders</div>
      <div class="spot-matches">
        <?php foreach (array_slice($allTags, 0, 30, true) as $tag => $cnt): ?>
          <span class="spot-match-tag" title="<?= esc($cnt) ?> order<?= $cnt !== 1 ? 's' : '' ?>">
            <?= esc($tag) ?> <span style="opacity:.6;font-weight:400"><?= $cnt ?></span>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Orders table -->
    <div class="table-wrap">
      <div class="table-header">
        <h2>Order History</h2>
        <div class="flex items-center gap-2">
          <span><?= count($orders) ?><?= $truncated ? '+' : '' ?> order<?= count($orders) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-customer" data-csv-filename="customer-<?= esc(str_replace(['@', '.'], ['-', '-'], $email)) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?php if ($truncated): ?>
        <div class="hint px-4 pb-2" style="color:var(--warn)">Results truncated - showing first 250+ orders.</div>
      <?php endif; ?>
      <table id="tbl-customer">
        <thead>
          <tr>
            <th style="width:1.5rem"></th>
            <th>Order</th>
            <th>Date</th>
            <th>Financial</th>
            <th>Fulfillment</th>
            <th>Total</th>
            <th>Tags</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o):
            $legacyId  = $o['legacyResourceId'] ?? '';
            $gqlId     = $o['id'] ?? '';
            $url       = $legacyId ? $shopifyAdminBase . '/' . $legacyId : null;
            $fin       = $o['displayFinancialStatus'] ?? '-';
            $ful       = $o['displayFulfillmentStatus'] ?? '-';
            $amount    = $o['totalPriceSet']['shopMoney']['amount']       ?? null;
            $cur       = $o['totalPriceSet']['shopMoney']['currencyCode'] ?? '';
            $tags      = (array)($o['tags'] ?? []);
            $cancelled = !empty($o['cancelledAt']);
            $rowId     = 'od-' . $legacyId;

            $finChip = match(strtolower($fin)) {
              'paid'                      => 'chip-paid',
              'partially_paid','partially paid' => 'chip-partial',
              'unpaid','pending'          => 'chip-unpaid',
              default                     => 'chip-unknown',
            };
          ?>
          <tr class="<?= $cancelled ? 'opacity-50' : '' ?> order-summary-row"
              data-order-id="<?= esc($legacyId) ?>"
              style="cursor:pointer"
              onclick="toggleOrderDetail('<?= esc($legacyId) ?>', this)">
            <td class="text-center" style="color:var(--muted);font-size:.8rem">
              <span class="order-expand-icon" id="icon-<?= esc($legacyId) ?>">▶</span>
            </td>
            <td class="order-num">
              <?php if ($url): ?>
                <a href="<?= esc($url) ?>" target="_blank" rel="noopener"
                   onclick="event.stopPropagation()"><?= esc($o['name']) ?></a>
              <?php else: ?>
                <?= esc($o['name']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc(ltrim($o['name'], '#')) ?>"
                      title="Copy" onclick="event.stopPropagation()">⧉</button>
              <?php if ($cancelled): ?>
                <span class="source-badge" style="font-size:.65rem">cancelled</span>
              <?php endif; ?>
            </td>
            <td class="td-email"><?= esc(substr($o['createdAt'], 0, 10)) ?></td>
            <td><span class="chip <?= $finChip ?>"><?= esc($fin) ?></span></td>
            <td><span class="chip chip-unknown"><?= esc($ful) ?></span></td>
            <td class="td-price"><?= $amount !== null ? esc($cur) . ' ' . number_format((float)$amount, 2) : '-' ?></td>
            <td>
              <div class="spot-matches">
                <?php foreach ($tags as $t): ?>
                  <span class="spot-match-tag spot-match-tag-sh"><?= esc($t) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="td-actions" onclick="event.stopPropagation()">
              <?php if ($legacyId): ?>
                <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($o['name'], '#')) ?>">Spot-check</a>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="order-detail-row" id="<?= esc($rowId) ?>" style="display:none">
            <td colspan="8" style="padding:0">
              <div class="order-detail-panel" id="panel-<?= esc($legacyId) ?>">
                <div class="order-detail-loading">Loading…</div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>
<?php endif; ?>
