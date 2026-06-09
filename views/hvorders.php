<?= topbar('High-Value No Phone', 'Paid, unfulfilled high-value orders missing a shipping phone number') ?>

<?= featureInfoStart('hvorders', 'High-Value No Phone') ?>
    <p><strong>High-Value No Phone</strong> finds paid, unfulfilled orders above a threshold where the shipping address has no phone number. Carriers often require a phone for high-value shipments.</p>
    <p>Use this to proactively reach out to customers before shipping to collect a phone number and avoid carrier rejections or delayed deliveries.</p>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid, unfulfilled orders and filters to those above the minimum order value with no shipping phone.</div>

  <?php if ($hvError): ?>
    <div class="error-msg mb-3"><?= esc($hvError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_hvorders">
    <?php
$partialStartName = 'hv_start'; $partialStartVal = $hvStart;
$partialEndName   = 'hv_end';   $partialEndVal   = $hvEnd;
$partialExtraHtml = '<div class="field"><label>Min order value $</label><input type="number" name="hv_min" value="' . (int)$hvMin . '" min="0" step="10" style="width:100px"></div>';
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($hvResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $hvResult['scanned'] ?></strong> orders
        (<?= esc($hvResult['start']) ?> → <?= esc($hvResult['end']) ?>)
        &mdash; <strong><?= count($hvResult['rows']) ?></strong> high-value order<?= count($hvResult['rows']) !== 1 ? 's' : '' ?> without phone</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($hvResult !== null): ?>
  <?php if (empty($hvResult['rows'])): ?>
    <?= tableWrapEmpty('No issues found', 'All orders above $' . (int)$hvResult['min'] . ' have a shipping phone number in this date range.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($hvResult['rows'], 'tbl-hvorders', 'High-Value Orders Without Phone', 'hv-no-phone', $hvResult['start']) ?>
      <table id="tbl-hvorders">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Shipping Address</th>
            <th>Order Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hvResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $addr     = $row['address'];
            $addrLine = formatAddressLine($addr);
            $recipientName = trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? ''));
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td><?= esc($row['created_at']) ?></td>
            <td class="td-email">
              <?php if ($row['email']): ?>
                <a href="?page=customer&email=<?= urlencode($row['email']) ?>"><?= esc($row['email']) ?></a>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td class="td-email">
              <?php if ($recipientName): ?>
                <div class="font-medium"><?= esc($recipientName) ?></div>
              <?php endif; ?>
              <?php if ($addrLine): ?>
                <div class="text-xs text-muted"><?= esc($addrLine) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-price"><strong><?= formatPrice($row['total']) ?></strong></td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'shopifyLabel' => 'Edit in Shopify', 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
