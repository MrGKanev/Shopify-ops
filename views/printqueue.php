<?= topbar('Print Queue', 'Queue ShipStation order numbers for packing-slip printing') ?>

<?= featureInfoStart('printqueue', 'Print Queue') ?>
  <p><strong>Print Queue</strong> maintains a lightweight list of ShipStation order numbers you want to print packing slips for.</p>
  <ul>
    <li>Add orders one at a time using the form below, or remove individual entries.</li>
    <li><strong>Print All</strong> opens the <em>Packing Slip Preview</em> for each queued order in a new tab.</li>
    <li>The queue is stored in <code>data/print_queue.json</code> and persists across sessions.</li>
  </ul>
<?= featureInfoEnd() ?>

<?php if ($pqMessage): ?>
  <div class="flash flash-ok"><?= esc($pqMessage) ?></div>
<?php endif; ?>
<?php if ($pqError): ?>
  <div class="flash flash-err"><?= esc($pqError) ?></div>
<?php endif; ?>

<div class="run-form">
  <h2>Add to queue</h2>
  <form method="post" class="flex gap-3 flex-wrap items-end">
    <input type="hidden" name="action" value="pq_add">
    <div>
      <label class="label" for="pq_order_number">Order Number</label>
      <input type="text" id="pq_order_number" name="pq_order_number"
             class="input" placeholder="e.g. 65075"
             autocomplete="off" spellcheck="false" required>
    </div>
    <div>
      <label class="label" for="pq_note">Note (optional)</label>
      <input type="text" id="pq_note" name="pq_note" class="input" placeholder="e.g. fragile">
    </div>
    <button class="btn btn-submit-end" type="submit">Add to Queue</button>
  </form>
</div>

<?php if (empty($pqItems)): ?>
  <div class="table-wrap">
    <div class="empty">
      <div class="icon">🖨</div>
      <h3>Queue is empty</h3>
      <p>Add ShipStation order numbers above to build your print queue.</p>
    </div>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <div class="table-header">
      <h2>Queued Orders</h2>
      <div class="flex items-center gap-2">
        <span><?= count($pqItems) ?> order<?= count($pqItems) !== 1 ? 's' : '' ?></span>
        <button class="btn btn-sm btn-ghost"
                onclick="printAll()"
                type="button">Print All</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Clear the entire print queue?')">
          <input type="hidden" name="action" value="pq_clear">
          <button class="btn btn-sm btn-ghost" type="submit" style="color:var(--danger)">Clear All</button>
        </form>
      </div>
    </div>
    <table id="tbl-printqueue">
      <thead>
        <tr>
          <th>Order Number</th>
          <th>Note</th>
          <th>Queued At</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pqItems as $item): ?>
        <tr>
          <td class="font-semibold font-mono">
            <a href="?page=packingslip&order=<?= urlencode($item['order_number']) ?>" target="_blank" rel="noopener">
              <?= esc($item['order_number']) ?>
            </a>
          </td>
          <td class="text-sm text-muted"><?= $item['note'] !== '' ? esc($item['note']) : '—' ?></td>
          <td class="text-sm"><?= esc($item['queued_at'] ?? '') ?></td>
          <td class="td-actions">
            <a class="ignore-btn" href="?page=packingslip&order=<?= urlencode($item['order_number']) ?>"
               target="_blank" rel="noopener">Print</a>
            <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode($item['order_number']) ?>">Spot-check</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="pq_remove">
              <input type="hidden" name="pq_order_number" value="<?= esc($item['order_number']) ?>">
              <button class="ignore-btn" type="submit" style="color:var(--danger);background:none;border:none;cursor:pointer;padding:0">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script>
  function printAll() {
    var orders = <?= json_encode(array_column($pqItems, 'order_number')) ?>;
    orders.forEach(function(num) {
      window.open('?page=packingslip&order=' + encodeURIComponent(num), '_blank');
    });
  }
  </script>
<?php endif; ?>
