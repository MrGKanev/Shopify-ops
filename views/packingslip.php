<?= topbar('Packing Slip Preview', 'Visualise a ShipStation packing slip for any order') ?>

<style>
@media print {
  .sidebar,.mobile-header,.topbar,.breadcrumb,
  .ps-toolbar,.ps-bug-tag,.spot-form,.error-msg { display:none !important; }
  .layout    { display:block !important; }
  .main      { margin-left:0 !important; padding:0 !important; max-width:100% !important; }
  .packing-slip { box-shadow:none !important; border:none !important; max-width:100% !important; margin:0 !important; }
}
</style>

<div class="spot-form">
  <h2>Preview packing slip</h2>
  <div class="hint">Fetches live from ShipStation — results are not cached.</div>
  <form method="post">
    <input type="hidden" name="action" value="packingslip">
    <div class="flex gap-2 items-center mt-2">
      <input type="text" name="order_number"
             placeholder="162194"
             value="<?= esc($slipInput) ?>"
             class="ps-order-input">
      <button class="btn" type="submit">Preview slip</button>
    </div>
  </form>
</div>

<?php if ($slipError): ?>
  <div class="error-msg mb-4"><?= esc($slipError) ?></div>
<?php endif; ?>

<?php if ($slipOrder): ?>
<?php
  $o      = $slipOrder;
  $ship   = $o['shipTo']            ?? [];
  $num    = $o['orderNumber']       ?? '';
  $date   = $o['orderDate']         ?? '';
  $shipBy = $o['shipByDate']        ?? '';
  $user   = $o['customerUsername']  ?? '';
  $items  = $o['items']             ?? [];
  $cNotes  = $o['customerNotes']  ?? '';
  $iNotes  = $o['internalNotes'] ?? '';
  $advOpts = $o['advancedOptions'] ?? [];

  $fmtDate = fn($d) => $d ? date('n/j/Y', strtotime($d)) : '';

  // Internal SS / GPO option names to hide from packing slip
  $hiddenOptNames = [' has gpo', ' gpo product group', ' gpo parent product group', ' gpo field name',
                     ' gpo options', ' gpo addon products', 'fulfillment_status'];
  $isHidden = fn(string $n): bool => in_array(strtolower(trim($n)), $hiddenOptNames, true);

  // Detect Shopify JSON-array values: ["something"] or ["a","b"]
  $isJsonArr = fn(string $v): bool => (bool) preg_match('/^\[".+"\]/', trim($v));

  // Decode JSON array to readable form
  $cleanVal = function(string $v): string {
    $d = json_decode(trim($v), true);
    return is_array($d) ? implode(', ', $d) : $v;
  };

  // Parse <br/>-separated note strings
  $parseNotes = fn(string $n): array => array_values(array_filter(array_map('trim', explode('<br/>', $n))));

  // SS shows internalNotes first, then customerNotes, then customField1/2/3
  $allNotes = array_merge(
    $iNotes ? $parseNotes($iNotes) : [],
    $cNotes ? $parseNotes($cNotes) : [],
    array_values(array_filter(array_map('trim', [
      $advOpts['customField1'] ?? '',
      $advOpts['customField2'] ?? '',
      $advOpts['customField3'] ?? '',
    ])))
  );

  // Count items with JSON array bug for the warning banner
  $bugCount = 0;
  foreach ($items as $item) {
    foreach ($item['options'] ?? [] as $opt) {
      if (!$isHidden($opt['name'] ?? '') && $isJsonArr($opt['value'] ?? '')) {
        $bugCount++;
      }
    }
  }
?>

<div class="ps-toolbar">
  <button onclick="window.print()" class="btn btn-sm">Print</button>
  <span class="text-xs" style="color:var(--muted)">Sidebar hidden in print mode (Ctrl/⌘+P)</span>
  <?php if ($bugCount > 0): ?>
    <span class="ps-bug-banner">
      ⚠ <?= $bugCount ?> option<?= $bugCount !== 1 ? 's' : '' ?> with JSON-array value —
      highlighted below in yellow
    </span>
  <?php endif; ?>
</div>

<!-- ── Packing slip paper ─────────────────────────────────── -->
<div class="packing-slip">

  <!-- Top row: warehouse address | title | order number -->
  <div class="ps-top">
    <div class="ps-warehouse">
      <!-- configure SS_WAREHOUSE_ADDR in .env to populate this corner -->
    </div>
    <div class="ps-center-title">Packing Slip</div>
    <div class="ps-big-num"><?= esc($num) ?></div>
  </div>

  <!-- Address block + order meta -->
  <div class="ps-addr-row">
    <div class="ps-ship-to">
      <span class="ps-field-label">Ship To:</span>
      <div class="ps-addr-block">
        <?php if ($ship['name']       ?? ''): ?><div><?= esc($ship['name'])       ?></div><?php endif; ?>
        <?php if ($ship['company']    ?? ''): ?><div><?= esc($ship['company'])    ?></div><?php endif; ?>
        <?php if ($ship['street1']    ?? ''): ?><div><?= esc($ship['street1'])    ?></div><?php endif; ?>
        <?php if ($ship['street2']    ?? ''): ?><div><?= esc($ship['street2'])    ?></div><?php endif; ?>
        <div>
          <?php
            $parts = array_filter([
              $ship['city']       ?? '',
              $ship['state']      ?? '',
              $ship['postalCode'] ?? '',
              $ship['country']    ?? '',
            ]);
            // "CITY, STATE POSTALCODE COUNTRY"
            $city  = $ship['city']       ?? '';
            $state = $ship['state']      ?? '';
            $zip   = $ship['postalCode'] ?? '';
            $ctry  = $ship['country']    ?? '';
            $line  = $city . ($state ? ', ' . $state : '') . ($zip ? ' ' . $zip : '') . ($ctry ? ' ' . $ctry : '');
            echo esc(trim($line));
          ?>
        </div>
      </div>
    </div>

    <div class="ps-meta">
      <table class="ps-meta-table">
        <tr><th>Order #</th> <td><?= esc($num)               ?></td></tr>
        <tr><th>Date</th>    <td><?= esc($fmtDate($date))    ?></td></tr>
        <tr><th>User</th>    <td><?= esc($user)              ?></td></tr>
        <tr><th>Ship Date</th><td><?= esc($fmtDate($shipBy)) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- Items table -->
  <table class="ps-items">
    <thead>
      <tr>
        <th class="ps-desc-col">Description</th>
        <th class="ps-qty-col">Qty</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr class="ps-item">
          <td><?= esc($item['name'] ?? '') ?></td>
          <td class="ps-qty"><?= (int)($item['quantity'] ?? 0) ?: '' ?></td>
        </tr>
        <?php foreach ($item['options'] ?? [] as $opt): ?>
          <?php
            $oName  = $opt['name']  ?? '';
            $oVal   = $opt['value'] ?? '';
            if ($isHidden($oName)) continue;
            $hasBug = $isJsonArr($oVal);
            $line   = trim($oName) !== '' ? trim($oName) . ': ' . $oVal : $oVal;
          ?>
          <tr class="ps-opt<?= $hasBug ? ' ps-opt-bug' : '' ?>">
            <td colspan="2">
              <?= esc($line) ?>
              <?php if ($hasBug): ?>
                <span class="ps-bug-tag">
                  ⚠ JSON array — SS renders as-is. Decoded: <strong><?= esc($cleanVal($oVal)) ?></strong>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Notes -->
  <?php if ($allNotes): ?>
    <div class="ps-notes">
      <?php foreach ($allNotes as $note): ?>
        <div><?= esc($note) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Barcode (visual only) -->
  <div class="ps-barcode">
    <div class="ps-barcode-bars" aria-hidden="true"></div>
  </div>

</div><!-- /.packing-slip -->

<?php endif; ?>
