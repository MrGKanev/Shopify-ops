<?= topbar('Order Timeline', 'Full chronological history of a single order') ?>

<?= featureInfoStart('timeline', 'Order Timeline') ?>
  <p><strong>Order Timeline</strong> merges every event from Shopify and ShipStation into one reverse-chronological view for a single order.</p>
  <p>Useful for CS teams investigating fulfilment delays, customers asking &ldquo;where is my order?&rdquo;, or auditing what happened to a refunded/cancelled order.</p>
  <ul>
    <li>Shows <strong>order placement, payment, fulfillments, refunds, cancellations</strong> and the full Shopify audit trail.</li>
    <li>Includes <strong>ShipStation order status and shipment history</strong> if SS credentials are configured.</li>
    <li>Flags <strong>risk signals</strong>: slow ship time, cancelled-but-shipped, refunded-but-active-in-SS.</li>
    <li>The <strong>Copy as text</strong> button exports the timeline to clipboard for quick pasting into support tickets.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Enter an order number</h2>
  <div class="hint">Enter a Shopify order number. The # prefix is optional.</div>

  <?php if ($tlError): ?>
    <div class="error-msg mb-3"><?= esc($tlError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="order_timeline">
    <div class="date-row">
      <div class="field date-row-wide">
        <label>Order Number</label>
        <input type="text" name="tl_order" value="<?= esc($tlInput) ?>"
               placeholder="1234" autofocus>
      </div>
      <button class="btn btn-submit-end" type="submit">Load timeline</button>
    </div>
  </form>
</div>

<?php if ($tlResult !== null):
  $order     = $tlResult['order'];
  $timeline  = $tlResult['timeline'];
  $risks     = $tlResult['risks'];
  $tos       = $tlResult['time_to_ship'];
  $label     = $tlResult['label'];
  $ssOrders  = $tlResult['ss_orders'];
  $shopifyId = $order['id'] ?? '';
  $orderUrl  = $shopifyId ? $shopifyAdminBase . '/' . $shopifyId : null;

  $finStatus = $order['financial_status']    ?? '-';
  $fulStatus = $order['fulfillment_status']  ?? 'unfulfilled';
  $total     = (float) ($order['total_price'] ?? 0);
  $email     = $order['email']    ?? '';
  $createdAt = substr($order['created_at'] ?? '', 0, 10);
  $itemCount = count($order['line_items'] ?? []);
  $fulCount  = count($order['fulfillments'] ?? []);

  $finChip = match(strtolower($finStatus)) {
    'paid'                        => 'chip-paid',
    'partially_paid'              => 'chip-partial',
    'unpaid','pending'            => 'chip-unpaid',
    default                       => 'chip-unknown',
  };
?>

<!-- Order summary card -->
<div class="tl-order-card">
  <div class="tl-order-meta">
    <div class="tl-order-name">
      <?php if ($orderUrl): ?>
        <a href="<?= esc($orderUrl) ?>" target="_blank" rel="noopener"><?= esc($label) ?></a>
      <?php else: ?>
        <?= esc($label) ?>
      <?php endif; ?>
      <span class="chip <?= $finChip ?>" style="font-size:.75rem;vertical-align:middle;margin-left:.4rem"><?= esc($finStatus) ?></span>
      <?php if (!empty($order['cancelled_at'])): ?>
        <span class="chip chip-unpaid" style="font-size:.75rem;vertical-align:middle;margin-left:.3rem">cancelled</span>
      <?php endif; ?>
    </div>
    <?php if ($email): ?>
      <div class="tl-order-email"><?= esc($email) ?></div>
    <?php endif; ?>
    <?php if (!empty($order['tags'])): ?>
      <div class="spot-matches mt-2">
        <?php foreach (explode(', ', $order['tags']) as $tag): ?>
          <?php if (trim($tag)): ?>
            <span class="spot-match-tag"><?= esc(trim($tag)) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="tl-order-stats">
    <div class="tl-stat">
      <div class="tl-stat-val">$<?= number_format($total, 2) ?></div>
      <div class="tl-stat-lbl">Total</div>
    </div>
    <div class="tl-stat">
      <div class="tl-stat-val"><?= $itemCount ?></div>
      <div class="tl-stat-lbl">Item<?= $itemCount !== 1 ? 's' : '' ?></div>
    </div>
    <div class="tl-stat">
      <div class="tl-stat-val"><?= $fulCount ?: '-' ?></div>
      <div class="tl-stat-lbl">Shipment<?= $fulCount !== 1 ? 's' : '' ?></div>
    </div>
    <?php if ($tos !== null): ?>
    <div class="tl-stat">
      <div class="tl-stat-val" style="color:<?= $tos > 7 ? 'var(--danger)' : ($tos > 3 ? 'var(--warn)' : 'var(--ok)') ?>"><?= $tos ?>d</div>
      <div class="tl-stat-lbl">To ship</div>
    </div>
    <?php endif; ?>
    <div class="tl-stat">
      <div class="tl-stat-val" style="font-size:.9rem"><?= esc($createdAt) ?></div>
      <div class="tl-stat-lbl">Placed</div>
    </div>
    <?php if (!empty($ssOrders)): ?>
    <div class="tl-stat">
      <div class="tl-stat-val"><?= count($ssOrders) ?></div>
      <div class="tl-stat-lbl">SS record<?= count($ssOrders) !== 1 ? 's' : '' ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($risks)): ?>
<div class="tl-risks">
  <?php foreach ($risks as $r): ?>
    <div class="tl-risk tl-risk-<?= esc($r['level']) ?>">
      <?php if ($r['level'] === 'danger'): ?>&#9888;<?php elseif ($r['level'] === 'warn'): ?>&#9888;<?php else: ?>&#8505;<?php endif; ?>
      <?= esc($r['msg']) ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($timeline)): ?>
  <div class="table-wrap">
    <div class="empty">
      <div class="icon">&#128197;</div>
      <h3>No timeline events</h3>
      <p>No events could be built for this order.</p>
    </div>
  </div>
<?php else: ?>

<div class="tl-wrap">
  <div class="tl-header">
    <h2>Timeline &mdash; <?= count($timeline) ?> event<?= count($timeline) !== 1 ? 's' : '' ?></h2>
    <button class="btn btn-sm btn-ghost" id="tl-copy-btn" onclick="copyTimeline()">Copy as text</button>
  </div>

  <ul class="tl-list" id="tl-list">
    <?php foreach ($timeline as $item):
      $type    = $item['type'];
      $source  = $item['source'];
      $tsFmt   = $item['ts_fmt'];
      $title   = $item['title'];
      $detail  = $item['detail'];
      $tracking = $item['tracking'];
      $url     = $item['url'];
    ?>
    <li class="tl-item"
        data-ts="<?= esc($tsFmt) ?>"
        data-title="<?= esc($title) ?>"
        data-detail="<?= esc($detail) ?>"
        data-source="<?= esc($source) ?>">
      <div class="tl-dot tl-dot-<?= esc($type) ?>"></div>
      <div class="tl-content">
        <div class="tl-time"><?= esc($tsFmt) ?>
          <span class="tl-source tl-source-<?= esc($source) ?>"><?= $source === 'shipstation' ? 'ShipStation' : 'Shopify' ?></span>
        </div>
        <div class="tl-title">
          <?php if ($url): ?>
            <a href="<?= esc($url) ?>" target="_blank" rel="noopener"><?= esc($title) ?></a>
          <?php else: ?>
            <?= esc($title) ?>
          <?php endif; ?>
        </div>
        <?php if ($detail): ?>
          <div class="tl-detail"><?= esc($detail) ?></div>
        <?php endif; ?>
        <?php if ($tracking): ?>
          <div class="tl-tracking"><?= esc($tracking) ?></div>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<script>
function copyTimeline() {
  var btn   = document.getElementById('tl-copy-btn');
  var items = document.querySelectorAll('#tl-list .tl-item');
  var lines = ['Order Timeline: <?= esc(addslashes($label)) ?>',
               'Generated: ' + new Date().toISOString().slice(0, 10), ''];

  items.forEach(function(el) {
    var ts     = el.dataset.ts     || '';
    var title  = el.dataset.title  || '';
    var detail = el.dataset.detail || '';
    var source = el.dataset.source === 'shipstation' ? '[SS]' : '[Shopify]';
    lines.push(ts + '  ' + source + '  ' + title + (detail ? '  -  ' + detail : ''));
  });

  navigator.clipboard.writeText(lines.join('\n')).then(function() {
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = 'Copy as text'; }, 2000);
  }).catch(function() {
    btn.textContent = 'Failed';
    setTimeout(function() { btn.textContent = 'Copy as text'; }, 2000);
  });
}
</script>

<?php endif; ?>
<?php endif; ?>
