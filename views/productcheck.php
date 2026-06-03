<?= topbar('Product Completeness', 'Find active products missing images, descriptions, or SKUs') ?>

<?= featureInfoStart('productcheck', 'Product Completeness') ?>
  <p><strong>Product Completeness</strong> scans all active products in your Shopify store and flags those with missing or incomplete content.</p>
  <ul>
    <li><strong>Critical</strong> - one or more variants have no SKU set. This breaks fulfilment and inventory tracking.</li>
    <li><strong>Warning</strong> - product has no images, or no description. Both affect customer experience and SEO.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan active products</h2>
  <div class="hint">Fetches all active products and checks each one for missing content.</div>

  <?php if ($pcError): ?>
    <div class="error-msg mb-3"><?= esc($pcError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_products">
    <button class="btn" type="submit">Scan products</button>
  </form>

  <?php if ($pcResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $pcResult['scanned'] ?></strong> active products &mdash;
        <strong><?= count($pcResult['rows']) ?></strong> with issues</span>
      <?php if ($pcResult['critical'] > 0): ?>
        <span class="refund-risk-badge refund-risk-active"><?= $pcResult['critical'] ?> critical</span>
      <?php endif; ?>
      <?php if ($pcResult['warnings'] > 0): ?>
        <span class="refund-risk-badge refund-risk-missing"><?= $pcResult['warnings'] ?> warnings</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($pcResult !== null): ?>
  <?php if (empty($pcResult['rows'])): ?>
    <div class="empty">
      <div class="icon">✅</div>
      <h3>All products look complete!</h3>
      <p>Every active product has images, a description, and SKUs on all variants.</p>
    </div>
  <?php else: ?>
    <?php
      $shopifyProductBase = 'https://'
        . (str_contains($shopifyAdminBase, '//') ? parse_url($shopifyAdminBase, PHP_URL_HOST) : $shopifyAdminBase)
        . '/admin/products';
    ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Incomplete Products</h2>
        <div class="flex items-center gap-2">
          <span><?= count($pcResult['rows']) ?> product<?= count($pcResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-productcheck" data-csv-filename="product-completeness.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-productcheck">
        <thead>
          <tr>
            <th>Product</th>
            <th>Vendor</th>
            <th>Type</th>
            <th>Images</th>
            <th>Variants</th>
            <th>Issues</th>
            <th>Severity</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pcResult['rows'] as $row):
            $adminUrl = $row['id'] ? $shopifyProductBase . '/' . esc($row['id']) : null;
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['title']) ?></a>
              <?php else: ?>
                <?= esc($row['title']) ?>
              <?php endif; ?>
            </td>
            <td><?= esc($row['vendor'] ?: '-') ?></td>
            <td><?= esc($row['type'] ?: '-') ?></td>
            <td><?= $row['images'] ?></td>
            <td><?= $row['variants'] ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['issues'] as $issue): ?>
                  <span class="addr-issue addr-issue-<?= $issue['level'] ?>"><?= esc($issue['message']) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td>
              <span class="refund-risk-badge <?= $row['severity'] === 'critical' ? 'refund-risk-active' : 'refund-risk-missing' ?>">
                <?= $row['severity'] ?>
              </span>
            </td>
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Edit in Shopify</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
