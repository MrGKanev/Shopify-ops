<?= topbar('Tracking Feed', 'Look up shipment tracking info for orders via ShipStation') ?>

<?= featureInfoStart('tracking', 'Tracking Feed') ?>
    <p><strong>Tracking Feed</strong> looks up one or more order numbers in ShipStation and shows tracking details — carrier, tracking number, ship date — with a direct link to the carrier's tracking page.</p>
    <p>Useful for quickly answering "where is my order?" without leaving the app or navigating through ShipStation's interface.</p>
    <ul>
      <li>Enter up to <strong>30 order numbers</strong> at once.</li>
      <li>Tracking links are generated for USPS, FedEx, UPS, DHL, OnTrac, and LaserShip.</li>
      <li>If an order has multiple shipments (split fulfilment), all are shown.</li>
      <li>Orders not yet shipped will show their current ShipStation status.</li>
    </ul>
<?= featureInfoEnd() ?>

<div class="spot-form">
  <h2>Enter order numbers</h2>
  <div class="hint">One per line or comma-separated. The # prefix is optional. Max 30.</div>

  <?php if ($trackingError): ?>
    <div class="error-msg mb-3"><?= esc($trackingError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="lookup_tracking">
    <textarea name="tracking_orders" placeholder="100042&#10;100043&#10;100044"><?= esc($trackingInput) ?></textarea>
    <div class="spot-btn-row">
      <button class="btn" type="submit">Look up tracking</button>
    </div>
  </form>
</div>

<?php if ($trackingResults !== null): ?>
  <div class="spot-results">
    <?php foreach ($trackingResults as $result):
      $found = $result['found'];
    ?>
      <div class="spot-row <?= $found ? 'found' : 'missing' ?>">
        <div class="spot-row-body">
          <div class="spot-num">#<?= esc($result['number']) ?></div>

          <?php if (!$found): ?>
            <div class="spot-platform-row">
              <span class="spot-detail spot-not-found">Not found in ShipStation</span>
            </div>
          <?php else: ?>
            <?php foreach ($result['shipments'] as $s):
              $statusChip = match($s['orderStatus']) {
                'shipped'            => 'chip-paid',
                'awaiting_shipment'  => 'chip-partial',
                'cancelled', 'on_hold' => 'chip-unpaid',
                default              => 'chip-unknown',
              };
            ?>
              <div class="spot-platform-row">
                <span class="spot-platform-label ss-label">ShipStation</span>
                <div class="tracking-detail">
                  <span class="chip <?= $statusChip ?>"><?= esc(str_replace('_', ' ', $s['orderStatus'])) ?></span>
                  <?php if ($s['carrierCode']): ?>
                    <span class="source-badge cached"><?= esc(strtoupper($s['carrierCode'])) ?></span>
                  <?php endif; ?>
                  <?php if ($s['shipDate']): ?>
                    <span class="text-xs text-muted">Shipped: <?= esc(substr($s['shipDate'], 0, 10)) ?></span>
                  <?php endif; ?>
                  <?php if ($s['trackingNumber']): ?>
                    <?php if ($s['trackingUrl']): ?>
                      <a href="<?= esc($s['trackingUrl']) ?>" target="_blank" rel="noopener"
                         class="spot-match-tag">
                        <?= esc($s['trackingNumber']) ?> ↗
                      </a>
                    <?php else: ?>
                      <span class="spot-match-tag"><?= esc($s['trackingNumber']) ?></span>
                    <?php endif; ?>
                    <button class="copy-btn" style="opacity:1" data-copy="<?= esc($s['trackingNumber']) ?>" title="Copy tracking number">⧉</button>
                  <?php else: ?>
                    <span class="text-xs text-muted">No tracking number yet</span>
                  <?php endif; ?>
                  <?php if ($s['ssUrl']): ?>
                    <a href="<?= esc($s['ssUrl']) ?>" target="_blank" rel="noopener" class="ignore-btn">Open in SS</a>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <span class="spot-status-pill <?= $found ? 'pill-ss' : 'pill-none' ?>">
          <?= $found ? (count($result['shipments']) > 1 ? count($result['shipments']) . ' shipments' : 'Found') : 'Not found' ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
