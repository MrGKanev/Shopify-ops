<div class="topbar">
  <div>
    <h1>Metafields</h1>
    <div class="meta">Browse order metafield definitions and search by value</div>
  </div>
</div>

<?php if ($metafieldError): ?>
  <div class="error-msg"><?= esc($metafieldError) ?></div>
<?php endif; ?>

<!-- ── Definitions ─────────────────────────────────────────────────────── -->
<div class="table-wrap" style="margin-bottom:1.5rem">
  <div class="table-header">
    <h2>Order Metafield Definitions</h2>
    <?php if ($metafieldDefs !== null): ?>
      <span><?= count($metafieldDefs) ?> definition<?= count($metafieldDefs) !== 1 ? 's' : '' ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($metafieldDefs) && !$metafieldError): ?>
    <div class="empty">
      <div class="icon">📭</div>
      <h3>No definitions found</h3>
      <p>No metafield definitions are configured for orders in this store.</p>
    </div>
  <?php elseif (!empty($metafieldDefs)): ?>
    <table>
      <thead>
        <tr>
          <th>Namespace</th>
          <th>Key</th>
          <th>Type</th>
          <th>Name</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($metafieldDefs as $def): ?>
        <tr>
          <td class="mf-ns-cell"><?= esc($def['namespace'] ?? '-') ?></td>
          <td>
            <button class="ignore-btn btn-push mf-ns-cell"
                    onclick="fillSearch(<?= esc(json_encode($def['namespace'] ?? '')) ?>, <?= esc(json_encode($def['key'] ?? '')) ?>)"
                    title="Search orders by this field">
              <?= esc($def['key'] ?? '-') ?>
            </button>
          </td>
          <td><span class="chip chip-unknown"><?= esc($def['type']['name'] ?? $def['type'] ?? '-') ?></span></td>
          <td><?= esc($def['name'] ?? '-') ?></td>
          <td class="mf-ns-cell"><?= esc($def['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ── Search by value ────────────────────────────────────────────────── -->
<div class="run-form" style="margin-bottom:1.5rem">
  <h2>Search orders by metafield value</h2>
  <div class="hint">Find orders that have a specific metafield value. Leave <strong>Value</strong> empty to see all orders with that metafield (useful for checking what values exist).</div>

  <?php if ($metafieldSearchError): ?>
    <div class="error-msg" style="margin-bottom:.75rem"><?= esc($metafieldSearchError) ?></div>
  <?php endif; ?>

  <form method="post" id="js-mf-search-form">
    <input type="hidden" name="action" value="metafield_search">
    <div class="date-row" style="margin-bottom:.75rem">
      <div class="field">
        <label>Namespace</label>
        <input id="js-search-ns" type="text" name="mf_ns"
               value="<?= esc($metafieldSearch['namespace'] ?? '') ?>"
               placeholder="custom">
      </div>
      <div class="field">
        <label>Key</label>
        <input id="js-search-key" type="text" name="mf_key"
               value="<?= esc($metafieldSearch['key'] ?? '') ?>"
               placeholder="shipping_note">
      </div>
      <div class="field date-row-wide">
        <label>Value</label>
        <input type="text" name="mf_value"
               value="<?= esc($metafieldSearch['value'] ?? '') ?>"
               placeholder="exact or partial value">
      </div>
    </div>
    <div class="date-row">
      <div class="field">
        <label>From <span class="label-opt">(optional)</span></label>
        <input type="date" name="mf_start"
               value="<?= esc($metafieldSearch['start'] ?? '') ?>"
               max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To <span class="label-opt">(optional)</span></label>
        <input type="date" name="mf_end"
               value="<?= esc($metafieldSearch['end'] ?? '') ?>"
               max="<?= date('Y-m-d') ?>">
      </div>
      <div class="mf-hint">
        Scans orders page by page (up to 2,500 at a time).
        No date range — scans the most recent 2,500 orders.
      </div>
      <button class="btn btn-submit-end" type="submit">Search</button>
    </div>
  </form>

  <?php if ($metafieldSearch !== null): ?>
    <div class="mf-stats">
      <div class="duration-note" style="margin:0">
        Scanned <strong><?= $metafieldSearch['scanned'] ?></strong> orders
        (<?= $metafieldSearch['pages'] ?> page<?= $metafieldSearch['pages'] !== 1 ? 's' : '' ?>) &mdash;
        <strong><?= $metafieldSearch['with_mf'] ?></strong> have metafield
        <code><?= esc($metafieldSearch['namespace']) ?>.<?= esc($metafieldSearch['key']) ?></code>
        &mdash; <strong><?= count($metafieldSearch['orders']) ?></strong> match
        <?php if ($metafieldSearch['truncated']): ?>
          <span class="source-badge cached">results truncated — set a date range</span>
        <?php endif; ?>
      </div>
      <?php if (!empty($metafieldSearch['sample_values'])): ?>
        <div class="duration-note" style="margin:0">
          Sample values:
          <?php foreach ($metafieldSearch['sample_values'] as $sv): ?>
            <code class="mf-sample"><?= esc($sv) ?></code>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($metafieldSearch !== null): ?>
  <?php if (empty($metafieldSearch['orders'])): ?>
    <div class="table-wrap" style="margin-bottom:1.5rem">
      <div class="empty">
        <div class="icon">🔍</div>
        <h3>No orders found</h3>
        <p>No orders match this metafield value.</p>
      </div>
    </div>
  <?php else: ?>
    <?php
      $partialOrders      = $metafieldSearch['orders'];
      $partialTitle       = 'Results';
      $partialExtraHeader = 'Value';
      $partialExtraCell   = fn($o) => renderMetafieldValue($o['metafield']['value'] ?? '-');
      require __DIR__ . '/partials/gql-orders-table.php';
    ?>
  <?php endif; ?>
<?php endif; ?>

<!-- ── Look up metafields by order ───────────────────────────────────── -->
<div class="spot-form">
  <h2>Look up metafields by order number</h2>
  <div class="hint">Enter order numbers to fetch all their metafield values. Optionally filter by namespace.key or value text.</div>

  <?php if ($metafieldError && !$metafieldSearch): ?>
    <div class="error-msg" style="margin-bottom:.75rem"><?= esc($metafieldError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="metafield_lookup">
    <textarea name="mf_orders" placeholder="100042&#10;100043&#10;100044"><?= esc($metafieldInput) ?></textarea>
    <div class="spot-btn-row mf-lookup-row">
      <div class="field mf-lookup-field">
        <label>Filter by namespace.key or value <span class="label-opt">(optional)</span></label>
        <input id="js-mf-filter" class="mf-filter-input" type="text" name="mf_filter"
               value="<?= esc($metafieldFilter) ?>"
               placeholder="custom.note  or  a value substring">
      </div>
      <button class="btn btn-submit-end" type="submit">Fetch metafields</button>
    </div>
  </form>
</div>

<?php if ($metafieldOrders !== null): ?>
  <div class="spot-results" style="margin-top:1rem">
    <?php foreach ($metafieldOrders as $mo): ?>
      <div class="spot-row <?= $mo['found'] ? 'found' : 'missing' ?>">
        <div class="spot-row-body">
          <div class="spot-num"><?= esc($mo['name'] ?? ('#' . $mo['number'])) ?></div>

          <?php if (!$mo['found']): ?>
            <span class="spot-detail spot-not-found">Not found in Shopify</span>
          <?php elseif (empty($mo['metafields'])): ?>
            <span class="spot-detail spot-detail-muted">
              <?= $metafieldFilter ? 'No metafields match the filter' : 'No metafields on this order' ?>
            </span>
          <?php else: ?>
            <div class="table-wrap mf-nested-table">
              <table>
                <thead>
                  <tr>
                    <th>Namespace</th>
                    <th>Key</th>
                    <th>Type</th>
                    <th>Value</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($mo['metafields'] as $mf): ?>
                  <tr>
                    <td class="mf-ns-cell"><?= esc($mf['namespace'] ?? '-') ?></td>
                    <td class="mf-ns-cell" style="color:var(--text)"><?= esc($mf['key'] ?? '-') ?></td>
                    <td><span class="chip chip-unknown"><?= esc($mf['type'] ?? '-') ?></span></td>
                    <td class="mf-val-cell"><?= renderMetafieldValue($mf['value'] ?? '') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <span class="spot-status-pill <?= $mo['found'] ? 'pill-both' : 'pill-none' ?>">
          <?= $mo['found']
              ? count($mo['metafields']) . ' field' . (count($mo['metafields']) !== 1 ? 's' : '')
              : 'Not found' ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
