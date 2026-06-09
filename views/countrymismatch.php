<?= topbar('Billing ≠ Shipping Country', 'Paid orders where billing and shipping countries differ') ?>

<?= featureInfoStart('countrymismatch', 'Billing ≠ Shipping Country') ?>
  <p><strong>Billing ≠ Shipping Country</strong> finds paid orders where the billing address country is different from the shipping address country.</p>
  <p>A mismatch is one of Shopify's own documented fraud signals - common patterns include freight-forwarder addresses, stolen card use, and drop-ship fraud. The list is intended as a manual review queue: most orders will be legitimate, but the outliers are worth checking.</p>
  <ul>
    <li>Only <strong>paid and partially paid</strong> orders are included.</li>
    <li>Orders where either address is missing a country code are skipped.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid orders and flags those where billing country ≠ shipping country.</div>

  <?php if ($cmError): ?>
    <div class="error-msg mb-3"><?= esc($cmError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_country_mismatch">
    <?php dateRangePartial('cm', $cmStart, $cmEnd) ?>
  </form>

  <?php if ($cmResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $cmResult['scanned'] ?></strong> orders
        (<?= esc($cmResult['start']) ?> → <?= esc($cmResult['end']) ?>)
        &mdash; <strong><?= count($cmResult['rows']) ?></strong> with billing ≠ shipping country</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($cmResult !== null): ?>
  <?php if (empty($cmResult['rows'])): ?>
    <?= tableWrapEmpty('No country mismatches found', 'All ' . $cmResult['scanned'] . ' paid orders in this range have matching billing and shipping countries.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($cmResult['rows'], 'tbl-countrymismatch', 'Country Mismatches', 'country-mismatch', $cmResult['start'], 'order', 'Filter by order #, email, country…') ?>
      <table id="tbl-countrymismatch">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Billing country</th>
            <th>Shipping country</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cmResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td>
              <span class="chip chip-warn font-mono"><?= esc($row['bill_country']) ?></span>
              <?php if ($row['bill_name']): ?>
                <div class="text-xs text-muted mt-1"><?= esc($row['bill_name']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="chip chip-ok font-mono"><?= esc($row['ship_country']) ?></span></td>
            <td class="td-price"><?= formatPrice($row['total_price']) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <span class="chip <?= financialChip($row['financial']) ?> capitalize"><?= esc($row['financial']) ?></span>
                <?php if ($row['fulfillment']): ?>
                  <span class="chip chip-unknown capitalize text-xs"><?= esc($row['fulfillment']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true, 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
