<div class="topbar">
  <div>
    <h1>Order Compare</h1>
    <div class="meta">Place two orders side by side and highlight their differences</div>
  </div>
</div>

<div class="feature-info" data-info-key="compare">
  <button class="feature-info-toggle" aria-expanded="false"><svg width="12" height="12" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> About: Order Compare</button>
  <div class="feature-info-body">
    <p><strong>Order Compare</strong> fetches two orders from Shopify (and ShipStation if available) and displays them side by side so differences are immediately visible.</p>
    <p>Most useful when investigating duplicate orders, re-orders from the same customer, or when a customer claims their order details are wrong.</p>
    <ul>
      <li>Compares line items, shipping address, financial status, fulfilment status, order total, and tags.</li>
      <li>Fields that differ between the two orders are <strong>highlighted</strong>.</li>
      <li>ShipStation status is shown if credentials are configured.</li>
    </ul>
  </div>
</div>

<div class="run-form">
  <h2>Enter two order numbers</h2>
  <div class="hint">The # prefix is optional.</div>

  <?php if ($compareError): ?>
    <div class="error-msg mb-3"><?= esc($compareError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="compare_orders">
    <div class="date-row">
      <div class="field">
        <label>Order A</label>
        <input type="text" name="compare_a" value="<?= esc($compareA) ?>" placeholder="100042" autofocus>
      </div>
      <div class="field">
        <label>Order B</label>
        <input type="text" name="compare_b" value="<?= esc($compareB) ?>" placeholder="100043">
      </div>
      <button class="btn btn-submit-end" type="submit">Compare</button>
    </div>
  </form>
</div>

<?php if ($compareResult !== null):
  $oA = $compareResult['a']['shopify'];
  $oB = $compareResult['b']['shopify'];
  $ssA = $compareResult['a']['ss'];
  $ssB = $compareResult['b']['ss'];
  $numA = $compareResult['a']['num'];
  $numB = $compareResult['b']['num'];

  $diff = function($a, $b): bool { return (string)$a !== (string)$b; };

  $addrStr = function(?array $addr): string {
    if (!$addr) return '—';
    return implode(', ', array_filter([
      trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
      $addr['address1'] ?? '',
      $addr['address2'] ?? '',
      $addr['city'] ?? '',
      $addr['province_code'] ?? '',
      $addr['zip'] ?? '',
      $addr['country_code'] ?? '',
    ]));
  };

  $itemStr = function(?array $order): string {
    $items = [];
    foreach ($order['line_items'] ?? [] as $li) {
      $items[] = ($li['quantity'] ?? 1) . '× ' . ($li['title'] ?? '') .
                 ($li['variant_title'] ? ' (' . $li['variant_title'] . ')' : '');
    }
    return implode(', ', $items) ?: '—';
  };

  $rows = [
    ['label' => 'Order #',            'a' => $oA ? ($oA['name'] ?? '#'.$numA) : '#'.$numA,                    'b' => $oB ? ($oB['name'] ?? '#'.$numB) : '#'.$numB],
    ['label' => 'Date',               'a' => $oA ? substr($oA['created_at'] ?? '', 0, 10) : '—',              'b' => $oB ? substr($oB['created_at'] ?? '', 0, 10) : '—'],
    ['label' => 'Email',              'a' => $oA['email'] ?? '—',                                             'b' => $oB['email'] ?? '—'],
    ['label' => 'Financial status',   'a' => $oA['financial_status'] ?? '—',                                  'b' => $oB['financial_status'] ?? '—'],
    ['label' => 'Fulfilment status',  'a' => $oA['fulfillment_status'] ?? 'unfulfilled',                      'b' => $oB['fulfillment_status'] ?? 'unfulfilled'],
    ['label' => 'Total',              'a' => $oA ? '$' . number_format((float)($oA['total_price'] ?? 0), 2) : '—', 'b' => $oB ? '$' . number_format((float)($oB['total_price'] ?? 0), 2) : '—'],
    ['label' => 'Items',              'a' => $itemStr($oA),                                                    'b' => $itemStr($oB)],
    ['label' => 'Ship to',            'a' => $addrStr($oA['shipping_address'] ?? null),                       'b' => $addrStr($oB['shipping_address'] ?? null)],
    ['label' => 'Tags',               'a' => implode(', ', (array)($oA['tags'] ?? [])) ?: '—',                'b' => implode(', ', (array)($oB['tags'] ?? [])) ?: '—'],
    ['label' => 'Note',               'a' => $oA['note'] ?? '—',                                              'b' => $oB['note'] ?? '—'],
  ];

  if ($ssA || $ssB) {
    $ssStatusA = !empty($ssA) ? ($ssA[0]['orderStatus'] ?? '—') : 'Not found';
    $ssStatusB = !empty($ssB) ? ($ssB[0]['orderStatus'] ?? '—') : 'Not found';
    $rows[] = ['label' => 'ShipStation status', 'a' => $ssStatusA, 'b' => $ssStatusB];
  }
?>
  <div class="table-wrap">
    <div class="table-header">
      <h2>Comparison</h2>
      <?php $diffCount = count(array_filter($rows, fn($r) => $diff($r['a'], $r['b']))); ?>
      <span><?= $diffCount ?> difference<?= $diffCount !== 1 ? 's' : '' ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width:160px">Field</th>
          <th>
            <?php if ($oA): ?>
              <a href="<?= esc($shopifyAdminBase . '/' . ($oA['id'] ?? '')) ?>" target="_blank" rel="noopener">
                <?= esc($oA['name'] ?? '#'.$numA) ?>
              </a>
            <?php else: ?>
              #<?= esc($numA) ?> <span class="text-xs text-muted">(not found)</span>
            <?php endif; ?>
          </th>
          <th>
            <?php if ($oB): ?>
              <a href="<?= esc($shopifyAdminBase . '/' . ($oB['id'] ?? '')) ?>" target="_blank" rel="noopener">
                <?= esc($oB['name'] ?? '#'.$numB) ?>
              </a>
            <?php else: ?>
              #<?= esc($numB) ?> <span class="text-xs text-muted">(not found)</span>
            <?php endif; ?>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
          $isDiff = $diff($row['a'], $row['b']);
        ?>
          <tr class="<?= $isDiff ? 'compare-diff-row' : '' ?>">
            <td class="text-xs font-semibold text-muted uppercase tracking-wide"><?= esc($row['label']) ?></td>
            <td class="<?= $isDiff ? 'compare-diff-cell' : '' ?>"><?= esc($row['a']) ?></td>
            <td class="<?= $isDiff ? 'compare-diff-cell' : '' ?>"><?= esc($row['b']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
