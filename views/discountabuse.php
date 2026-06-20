<?= topbar('Discount Abuse', 'Discount code clusters at the same shipping address') ?>

<?= featureInfoStart('discountabuse', 'Discount Abuse') ?>
  <p><strong>Discount Abuse</strong> groups paid orders by discount code and shipping address, then flags clusters where multiple distinct emails used the same code at the same destination.</p>
  <ul>
    <li>Use the email threshold to tune sensitivity for family, office, or wholesale addresses.</li>
    <li>Clusters are sorted by distinct email count, then order count.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Find repeated discount use at the same address across different customer emails.</div>
  <?php if ($daError): ?><div class="error-msg mb-3"><?= esc($daError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="scan_discountabuse">
    <?php dateRangePartial('da', $daStart, $daEnd, '<div class="field"><label>Min emails</label><input type="number" name="da_min_emails" min="2" value="' . esc($daMinEmails) . '"></div>') ?>
  </form>
  <?php if ($daResult !== null): ?>
    <div class="duration-note mt-4 mb-0">Scanned <strong><?= $daResult['scanned'] ?></strong> paid orders - <strong><?= count($daResult['rows']) ?></strong> suspicious clusters</div>
  <?php endif; ?>
</div>

<?php if ($daResult !== null): ?>
  <?php if (empty($daResult['rows'])): ?>
    <?= tableWrapEmpty('No discount clusters', 'No discount code/address cluster met the configured threshold.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($daResult['rows'], 'tbl-discountabuse', 'Discount Clusters', 'discount-abuse', $daResult['start'], 'cluster', 'Filter by code, address, email…') ?>
      <table id="tbl-discountabuse">
        <thead><tr><th>Code</th><th>Address</th><th>Emails</th><th>Orders</th><th>Total</th><th>Order Details</th></tr></thead>
        <tbody>
          <?php foreach ($daResult['rows'] as $row): ?>
          <tr>
            <td><span class="chip chip-unpaid"><?= esc($row['code']) ?></span></td>
            <td>
              <strong><?= esc($row['addr_name'] ?: '-') ?></strong><br>
              <span class="text-sm text-muted"><?= esc($row['addr_line']) ?></span>
            </td>
            <td>
              <strong><?= (int)$row['email_count'] ?></strong>
              <div class="text-xs text-muted"><?= esc(implode(', ', array_slice($row['emails'], 0, 4))) ?><?= count($row['emails']) > 4 ? '…' : '' ?></div>
            </td>
            <td><?= (int)$row['order_count'] ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td>
              <details>
                <summary>View orders</summary>
                <div class="spot-matches mt-2">
                  <?php foreach ($row['orders'] as $o):
                    $adminUrl = $o['shopify_id'] ? $shopifyAdminBase . '/' . esc($o['shopify_id']) : null;
                  ?>
                    <a class="spot-match-tag spot-match-tag-sh" href="<?= esc($adminUrl ?: '?page=spotcheck&prefill=' . urlencode($o['order_number'])) ?>" target="<?= $adminUrl ? '_blank' : '_self' ?>" rel="noopener">
                      <?= esc($o['order_number']) ?> · <?= esc($o['email']) ?> · <?= formatPrice($o['total']) ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
