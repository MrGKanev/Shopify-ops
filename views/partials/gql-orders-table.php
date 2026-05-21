<?php
/**
 * Partial: GraphQL order results table.
 *
 * Required variables:
 *   $partialOrders       — array of GraphQL order nodes
 *   $partialTitle        — string, table header title
 *   $partialExtraHeader  — string|null, extra column header (null = no extra column)
 *   $partialExtraCell    — Closure(array $o): string|null, renders extra column content
 *   $shopifyAdminBase    — from global scope
 */
?>
<div class="table-wrap">
  <div class="table-header">
    <h2><?= esc($partialTitle) ?></h2>
    <span><?= count($partialOrders) ?> order<?= count($partialOrders) !== 1 ? 's' : '' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Order</th>
        <th>Date</th>
        <th>Email</th>
        <th>Financial</th>
        <th>Fulfillment</th>
        <th>Total</th>
        <?php if ($partialExtraHeader !== null): ?><th><?= esc($partialExtraHeader) ?></th><?php endif; ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($partialOrders as $o):
        $r = gqlOrderRow($o, $shopifyAdminBase);
      ?>
      <tr>
        <td class="order-num">
          <?php if ($r['url']): ?>
            <a href="<?= esc($r['url']) ?>" target="_blank" rel="noopener"><?= esc($r['name']) ?></a>
          <?php else: ?>
            <?= esc($r['name']) ?>
          <?php endif; ?>
        </td>
        <td class="td-email"><?= esc($r['date']) ?></td>
        <td class="td-email"><?= esc($r['email']) ?></td>
        <td><span class="chip <?= $r['finChip'] ?>"><?= esc($r['finLabel']) ?></span></td>
        <td><span class="chip chip-unknown"><?= esc($r['fulLabel']) ?></span></td>
        <td class="td-price"><?= $r['amount'] !== null ? $r['currency'] . ' ' . number_format((float)$r['amount'], 2) : '-' ?></td>
        <?php if ($partialExtraHeader !== null): ?>
          <td class="mf-val-cell"><?= $partialExtraCell ? ($partialExtraCell)($o) : '' ?></td>
        <?php endif; ?>
        <td class="td-actions">
          <?php if ($r['legacyId']): ?>
            <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode($r['name']) ?>">Spot-check</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
