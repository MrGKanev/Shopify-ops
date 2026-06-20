<?= topbar('Zombie Products', 'Active products that can never be purchased') ?>

<?= featureInfoStart('zombieproducts', 'Zombie Products') ?>
  <p><strong>Zombie Products</strong> finds active (published) products that cannot be purchased - either because they have no variants defined, or because every tracked variant is permanently out of stock with a <em>deny</em> oversell policy.</p>
  <ul>
    <li><strong>No variants</strong> - the product exists in the catalogue but has no purchasable options at all.</li>
    <li><strong>All at 0</strong> - every variant that has inventory tracking enabled (and denies overselling) currently has zero or negative stock. These show as "Sold Out" on the storefront indefinitely.</li>
    <li>Variants with <em>continue selling when out of stock</em> or <em>untracked inventory</em> are excluded from the zero-stock check.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan active products</h2>
  <div class="hint">Fetches all active products and identifies those that cannot be purchased.</div>

  <?php if ($zpError): ?>
    <div class="error-msg mb-3"><?= esc($zpError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_zombieproducts">
    <button class="btn" type="submit">Scan products</button>
  </form>

  <?php if ($zpResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $zpResult['scanned'] ?></strong> active products
        &mdash; <strong><?= count($zpResult['rows']) ?></strong> zombie<?= count($zpResult['rows']) !== 1 ? 's' : '' ?> found</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($zpResult !== null): ?>
  <?php if (empty($zpResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No zombie products</h3>
        <p>All <?= $zpResult['scanned'] ?> active products have at least one purchasable variant.</p>
      </div>
    </div>
  <?php else: ?>
    <?php
      $shopifyProductBase = 'https://'
        . (str_contains($shopifyAdminBase, '//') ? parse_url($shopifyAdminBase, PHP_URL_HOST) : $shopifyAdminBase)
        . '/admin/products';
    ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Zombie Products</h2>
        <div class="flex items-center gap-2">
          <span><?= count($zpResult['rows']) ?> product<?= count($zpResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-zombieproducts"
                  data-csv-filename="zombie-products.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-zombieproducts', 'Filter by title, vendor, type…') ?>
      <table id="tbl-zombieproducts">
        <thead>
          <tr>
            <th>Product</th>
            <th>Vendor</th>
            <th>Type</th>
            <th>Reason</th>
            <th>Detail</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($zpResult['rows'] as $row):
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
            <td>
              <span class="addr-issue addr-issue-<?= $row['reason'] === 'no_variants' ? 'critical' : 'warning' ?>">
                <?= $row['reason'] === 'no_variants' ? 'No variants' : 'Out of stock' ?>
              </span>
            </td>
            <td class="text-sm text-muted"><?= esc($row['detail']) ?>
              <?php if ($row['stock'] !== null): ?>
                (total: <?= (int)$row['stock'] ?>)
              <?php endif; ?>
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
