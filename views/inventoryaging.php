<?= topbar('Inventory Aging', 'Zero-stock active variants that still sold recently') ?>

<?= featureInfoStart('inventoryaging', 'Inventory Aging') ?>
  <p><strong>Inventory Aging</strong> compares active Shopify products against recent paid order sales and flags tracked variants at zero or negative stock that still sold in the selected period.</p>
  <ul>
    <li>Only tracked variants with oversell disabled are considered.</li>
    <li>Rows are sorted by recent quantity sold.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Sales window</h2>
  <div class="hint">Find active zero-stock variants with recent demand.</div>
  <?php if ($iaError): ?><div class="error-msg mb-3"><?= esc($iaError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="scan_inventoryaging">
    <?php dateRangePartial('ia', $iaStart, $iaEnd) ?>
  </form>
  <?php if ($iaResult !== null): ?>
    <div class="duration-note mt-4 mb-0">Scanned <strong><?= $iaResult['products'] ?></strong> products, <strong><?= $iaResult['variants'] ?></strong> variants, and <strong><?= $iaResult['orders'] ?></strong> orders — <strong><?= count($iaResult['rows']) ?></strong> zero-stock sellers</div>
  <?php endif; ?>
</div>

<?php if ($iaResult !== null): ?>
  <?php if (empty($iaResult['rows'])): ?>
    <?= tableWrapEmpty('No zero-stock sellers', 'No active tracked zero-stock variant had recent sales in the selected window.') ?>
  <?php else: ?>
    <?php $productBase = 'https://' . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com") . '/admin/products'; ?>
    <div class="table-wrap">
      <?= tableWrapHeader($iaResult['rows'], 'tbl-inventoryaging', 'Zero-Stock Recent Sellers', 'inventory-aging', $iaResult['start'], 'variant', 'Filter by product, SKU, last order…') ?>
      <table id="tbl-inventoryaging">
        <thead><tr><th>Product</th><th>Variant</th><th>SKU</th><th>Stock</th><th>Recent Qty</th><th>Last Sale</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($iaResult['rows'] as $row):
            $productUrl = $row['product_id'] ? $productBase . '/' . esc($row['product_id']) : null;
          ?>
          <tr>
            <td>
              <?php if ($productUrl): ?><a href="<?= esc($productUrl) ?>" target="_blank" rel="noopener"><?= esc($row['product_title']) ?></a><?php else: ?><?= esc($row['product_title']) ?><?php endif; ?>
            </td>
            <td><?= esc($row['variant_title']) ?></td>
            <td class="font-mono text-sm"><?= esc($row['sku']) ?></td>
            <td><span class="chip chip-unpaid"><?= (int)$row['stock'] ?></span></td>
            <td><strong><?= (int)$row['recent_qty'] ?></strong></td>
            <td><?= esc($row['last_date']) ?> <span class="text-xs text-muted"><?= esc($row['last_order']) ?></span></td>
            <td class="td-actions">
              <?php if ($productUrl): ?><a class="ignore-btn" href="<?= esc($productUrl) ?>" target="_blank" rel="noopener">View Product</a><?php endif; ?>
              <?php if ($row['last_order']): ?><a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($row['last_order'], '#')) ?>">Spot-check</a><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
