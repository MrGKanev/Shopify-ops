<div class="topbar">
  <div>
    <h1>Spot-check Orders</h1>
    <div class="meta">Look up specific order numbers live in ShipStation and/or Shopify</div>
  </div>
</div>

<div class="spot-form">
  <h2>Enter order numbers</h2>
  <div class="hint">One per line, or comma-separated. The # prefix is optional.</div>

  <?php if ($spotError): ?>
    <div class="error-msg" style="margin-bottom:.75rem"><?= esc($spotError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="spotcheck">
    <input type="hidden" name="spotcheck_mode" id="js-spotcheck-mode" value="both">
    <textarea name="orders" placeholder="164777&#10;164789&#10;164812"><?= esc($spotInput) ?></textarea>
    <div class="spot-btn-row">
      <button class="btn" type="submit" onclick="document.getElementById('js-spotcheck-mode').value='both'">
        ShipStation &amp; Shopify
      </button>
      <button class="btn btn-ghost" type="submit" onclick="document.getElementById('js-spotcheck-mode').value='ss'">
        ShipStation only
      </button>
      <button class="btn btn-ghost" type="submit" onclick="document.getElementById('js-spotcheck-mode').value='shopify'">
        Shopify only
      </button>
    </div>
  </form>
</div>

<?php if ($spotResults !== null): ?>
  <?php
    $mode         = $spotResults[0]['mode'] ?? 'both';
    $checkSS      = in_array($mode, ['both', 'ss']);
    $checkSh      = in_array($mode, ['both', 'shopify']);
    $ssFoundCount = count(array_filter($spotResults, fn($r) => $r['ss_found']));
    $shFoundCount = count(array_filter($spotResults, fn($r) => $r['shopify_found']));
    $total        = count($spotResults);
  ?>
  <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem">
    <span style="font-size:.85rem;color:var(--muted)"><?= $total ?> checked &mdash;</span>
    <?php if ($checkSS): ?>
      <span class="source-badge <?= $ssFoundCount > 0 ? 'live' : 'cached' ?>">
        ShipStation: <?= $ssFoundCount ?>/<?= $total ?> found
      </span>
    <?php endif; ?>
    <?php if ($checkSh): ?>
      <span class="source-badge <?= $shFoundCount > 0 ? 'live' : 'cached' ?>">
        Shopify: <?= $shFoundCount ?>/<?= $total ?> found
      </span>
    <?php endif; ?>
  </div>

  <div class="spot-results">
    <?php foreach ($spotResults as $sc):
      $scMode   = $sc['mode'] ?? 'both';
      $ssFound  = $sc['ss_found'];
      $shFound  = $sc['shopify_found'];
      $allFound = $scMode === 'ss' ? $ssFound : ($scMode === 'shopify' ? $shFound : ($ssFound && $shFound));
      $anyFound = $ssFound || $shFound;
      $rowClass = $allFound ? 'found' : ($anyFound ? 'partial' : 'missing');
    ?>
      <div class="spot-row <?= $rowClass ?>">
        <div style="flex:1;min-width:0">
          <div class="spot-num">#<?= esc($sc['number']) ?></div>

          <?php if ($sc['ss_orders'] !== null): ?>
          <div class="spot-platform-row">
            <span class="spot-platform-label ss-label">ShipStation</span>
            <?php if ($ssFound): ?>
              <div class="spot-matches">
                <?php foreach ($sc['ss_orders'] as $o):
                  $ssUrl = !empty($o['orderId'])
                    ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($o['orderId'])
                    : null;
                ?>
                  <?php if ($ssUrl): ?><a class="spot-match-tag" href="<?= esc($ssUrl) ?>" target="_blank" rel="noopener"><?php else: ?><span class="spot-match-tag"><?php endif; ?>
                    #<?= esc($o['orderNumber'] ?? '?') ?>
                    &middot; <?= esc($o['orderStatus'] ?? '?') ?>
                    <?php if (!empty($o['orderTotal'])): ?>&middot; $<?= number_format((float)$o['orderTotal'], 2) ?><?php endif; ?>
                  <?php echo $ssUrl ? '</a>' : '</span>'; ?>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span class="spot-detail spot-not-found">Not found in ShipStation</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($sc['shopify_orders'] !== null): ?>
          <div class="spot-platform-row">
            <span class="spot-platform-label sh-label">Shopify</span>
            <?php if ($shFound): ?>
              <div class="spot-matches">
                <?php foreach ($sc['shopify_orders'] as $o):
                  $shUrl = !empty($o['id']) ? $shopifyAdminBase . '/' . $o['id'] : null;
                ?>
                  <?php if ($shUrl): ?><a class="spot-match-tag spot-match-tag-sh" href="<?= esc($shUrl) ?>" target="_blank" rel="noopener"><?php else: ?><span class="spot-match-tag spot-match-tag-sh"><?php endif; ?>
                    <?= esc($o['name'] ?? '#' . $o['order_number'] ?? '?') ?>
                    &middot; <?= esc($o['financial_status'] ?? '?') ?>
                    <?php if (!empty($o['total_price'])): ?>&middot; $<?= number_format((float)$o['total_price'], 2) ?><?php endif; ?>
                  <?php echo $shUrl ? '</a>' : '</span>'; ?>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span class="spot-detail spot-not-found">Not found in Shopify</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <span class="spot-status-pill
          <?= $allFound ? 'pill-both' : ($ssFound ? 'pill-ss' : ($shFound ? 'pill-sh' : 'pill-none')) ?>">
          <?php if ($scMode === 'ss'):   echo $ssFound ? 'Found' : 'Not found';
          elseif ($scMode === 'shopify'): echo $shFound ? 'Found' : 'Not found';
          elseif ($ssFound && $shFound): echo 'Both found';
          elseif ($ssFound):             echo 'SS only';
          elseif ($shFound):             echo 'Shopify only';
          else:                          echo 'Not found';
          endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
