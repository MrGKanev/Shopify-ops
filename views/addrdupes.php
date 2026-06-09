<?= topbar('Duplicate Shipping Addresses', 'Different customer emails shipping to the same address') ?>

<?= featureInfoStart('addrdupes', 'Duplicate Shipping Addresses') ?>
  <p><strong>Duplicate Shipping Addresses</strong> finds paid orders where two or more <em>different</em> customer emails are shipping to the exact same address — a signal for multi-account abuse, reseller networks, or dropshipping schemes.</p>
  <ul>
    <li>Matching is based on <strong>address1 + city + ZIP + country</strong> (normalised to lowercase). Province and name are not part of the key so slight variations don't hide matches.</li>
    <li>Multiple orders from the <em>same email</em> to the same address are excluded — only cross-email duplicates are flagged.</li>
    <li>Sort by <strong>Emails</strong> descending to find the most suspicious clusters first.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid orders and groups shipping addresses shared by more than one email.</div>

  <?php if ($adError): ?>
    <div class="error-msg mb-3"><?= esc($adError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_addrdupes">
    <?php
$partialStartName = 'ad_start'; $partialStartVal = $adStart;
$partialEndName   = 'ad_end';   $partialEndVal   = $adEnd;
require __DIR__ . '/partials/_date-range.php';
?>
  </form>

  <?php if ($adResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $adResult['scanned'] ?></strong> paid order<?= $adResult['scanned'] !== 1 ? 's' : '' ?>
        (<?= esc($adResult['start']) ?> → <?= esc($adResult['end']) ?>)
        &mdash; <strong><?= count($adResult['rows']) ?></strong> shared address<?= count($adResult['rows']) !== 1 ? 'es' : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($adResult !== null): ?>
  <?php if (empty($adResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No duplicate shipping addresses</h3>
        <p>Every shipping address in this range is used by only one customer email.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Shared Addresses</h2>
        <div class="flex items-center gap-2">
          <span><?= count($adResult['rows']) ?> address<?= count($adResult['rows']) !== 1 ? 'es' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-addrdupes"
                  data-csv-filename="addr-dupes-<?= esc($adResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <div class="search-wrap mb-3">
        <input class="js-search" data-target="tbl-addrdupes" placeholder="Filter by address, email…" type="search">
      </div>
      <table id="tbl-addrdupes">
        <thead>
          <tr>
            <th>Address</th>
            <th>Name on file</th>
            <th>Emails</th>
            <th>Orders</th>
            <th>Order details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($adResult['rows'] as $row): ?>
          <tr>
            <td class="text-sm">
              <div class="font-medium"><?= esc($row['addr_line']) ?></div>
            </td>
            <td class="text-sm text-muted"><?= $row['addr_name'] ? esc($row['addr_name']) : '-' ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['emails'] as $email): ?>
                  <a class="text-sm" href="?page=customer&email=<?= urlencode($email) ?>"><?= esc($email) ?></a>
                <?php endforeach; ?>
              </div>
              <span class="badge badge-warn" style="margin-top:4px"><?= $row['email_count'] ?> emails</span>
            </td>
            <td class="text-sm"><?= $row['order_count'] ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['orders'] as $o):
                  $adminUrl = $o['shopify_id'] ? $shopifyAdminBase . '/' . esc($o['shopify_id']) : null;
                ?>
                  <div class="text-sm flex items-center gap-2">
                    <?php if ($adminUrl): ?>
                      <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($o['order_number']) ?></a>
                    <?php else: ?>
                      <span><?= esc($o['order_number']) ?></span>
                    <?php endif; ?>
                    <span class="text-muted">·</span>
                    <span class="text-muted"><?= esc($o['created_at']) ?></span>
                    <span class="text-muted">·</span>
                    <span class="text-muted"><?= esc($o['email']) ?></span>
                    <span class="text-muted">·</span>
                    <span><?= formatPrice($o['total']) ?></span>
                    <?php if ($o['fulfillment']): ?>
                      <span class="chip chip-partial" style="font-size:.7rem"><?= esc(str_replace('_',' ',$o['fulfillment'])) ?></span>
                    <?php endif; ?>
                  </div>
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
