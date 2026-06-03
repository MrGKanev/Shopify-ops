<?= topbar('SKU Duplicates', 'Find variants sharing the same SKU across your catalog') ?>

<?= featureInfoStart('skudupes', 'SKU Duplicates') ?>
  <p><strong>SKU Duplicate Detector</strong> scans every product variant in your Shopify catalog and flags SKUs that appear more than once.</p>
  <p>Duplicate SKUs cause inventory tracking errors, incorrect fulfilment routing, and reporting anomalies. Each SKU should uniquely identify one variant.</p>
  <ul>
    <li>Scans all products regardless of status (active, draft, archived).</li>
    <li>Variants with no SKU are ignored - use <a href="?page=productcheck">Product Completeness</a> to find those.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan product catalog</h2>
  <div class="hint">Fetches all products and variants and reports any SKU that appears more than once.</div>

  <?php if ($sdError): ?>
    <div class="error-msg mb-3"><?= esc($sdError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_skudupes">
    <button class="btn" type="submit">Scan catalog</button>
  </form>

  <?php if ($sdResult !== null): ?>
    <div class="duration-note mt-4 mb-0">
      Scanned <strong><?= $sdResult['scanned'] ?></strong> products,
      <strong><?= $sdResult['variants'] ?></strong> variants &mdash;
      <strong><?= count($sdResult['rows']) ?></strong> duplicate SKU<?= count($sdResult['rows']) !== 1 ? 's' : '' ?> found
    </div>
  <?php endif; ?>
</div>

<?php if ($sdResult !== null): ?>
  <?php if (empty($sdResult['rows'])): ?>
    <div class="empty">
      <div class="icon">✅</div>
      <h3>No duplicate SKUs found!</h3>
      <p>Every variant across your <?= $sdResult['scanned'] ?> products has a unique SKU.</p>
    </div>
  <?php else: ?>
    <?php
      $shopifyProductBase = 'https://'
        . (str_contains($shopifyAdminBase, '//') ? parse_url($shopifyAdminBase, PHP_URL_HOST) : $shopifyAdminBase)
        . '/admin/products';
    ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Duplicate SKUs</h2>
        <div class="flex items-center gap-2">
          <span><?= count($sdResult['rows']) ?> SKU<?= count($sdResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-skudupes" data-csv-filename="sku-duplicates.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-skudupes">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Occurrences</th>
            <th>Products / Variants</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sdResult['rows'] as $row): ?>
          <tr>
            <td class="order-num">
              <?= esc($row['sku']) ?>
              <button class="copy-btn" style="opacity:1" data-copy="<?= esc($row['sku']) ?>" title="Copy SKU">⧉</button>
            </td>
            <td>
              <span class="refund-risk-badge refund-risk-active"><?= $row['count'] ?>×</span>
            </td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['variants'] as $v):
                  $pUrl = $v['product_id'] ? $shopifyProductBase . '/' . esc($v['product_id']) : null;
                  $statusChip = match($v['product_status']) {
                      'active'   => 'chip-type-1',
                      'draft'    => 'chip-unknown',
                      'archived' => 'chip-type-4',
                      default    => 'chip-unknown',
                  };
                ?>
                  <span class="text-sm">
                    <?php if ($pUrl): ?>
                      <a href="<?= $pUrl ?>" target="_blank" rel="noopener"><?= esc($v['product_title']) ?></a>
                    <?php else: ?>
                      <?= esc($v['product_title']) ?>
                    <?php endif; ?>
                    <?php if ($v['variant_title'] && $v['variant_title'] !== 'Default Title'): ?>
                      <span class="text-muted"> - <?= esc($v['variant_title']) ?></span>
                    <?php endif; ?>
                    <span class="chip <?= $statusChip ?>" style="font-size:.65rem;padding:.1rem .4rem"><?= esc($v['product_status']) ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
