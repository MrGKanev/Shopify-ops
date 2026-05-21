<div class="topbar">
  <div>
    <h1>Tag Search</h1>
    <div class="meta">Find all orders with a specific Shopify tag</div>
  </div>
</div>

<div class="run-form" style="margin-bottom:1.5rem">
  <h2>Search by tag</h2>
  <div class="hint">Exact tag match — case-insensitive. Uses Shopify's native tag index so results are fast regardless of date range.</div>

  <?php if ($tagSearchError): ?>
    <div class="error-msg" style="margin-bottom:.75rem"><?= esc($tagSearchError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="tag_search">
    <div class="date-row" style="margin-bottom:.75rem">
      <div class="field date-row-wide">
        <label>Tag</label>
        <input type="text" name="tag_input" value="<?= esc($tagInput) ?>" placeholder="wholesale, vip, reorder…" autofocus>
      </div>
    </div>
    <div class="date-row">
      <div class="field">
        <label>From <span class="label-opt">(optional)</span></label>
        <input type="date" name="tag_start" value="<?= esc($tagStart) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To <span class="label-opt">(optional)</span></label>
        <input type="date" name="tag_end" value="<?= esc($tagEnd) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="mf-hint">Tag filtering is indexed by Shopify — no need to paginate through all orders.</div>
      <button class="btn btn-submit-end" type="submit">Search</button>
    </div>
  </form>

  <?php if ($tagSearch !== null): ?>
    <div class="duration-note" style="margin-top:1rem;margin-bottom:0">
      Tag <code><?= esc($tagSearch['tag']) ?></code>
      <?php if ($tagSearch['start'] || $tagSearch['end']): ?>
        &middot; <?= esc($tagSearch['start'] ?: '…') ?> → <?= esc($tagSearch['end'] ?: '…') ?>
      <?php endif; ?>
      &mdash; <strong><?= count($tagSearch['matches']) ?></strong> order<?= count($tagSearch['matches']) !== 1 ? 's' : '' ?> found
      <?php if ($tagSearch['truncated']): ?>
        <span class="source-badge cached">results truncated — set a date range</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($tagSearch !== null): ?>
  <?php if (empty($tagSearch['matches'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">🏷️</div>
        <h3>No orders found</h3>
        <p>No orders have the tag <code><?= esc($tagSearch['tag']) ?></code><?= ($tagSearch['start'] || $tagSearch['end']) ? ' in this date range' : '' ?>.</p>
      </div>
    </div>
  <?php else: ?>
    <?php
      $activeTag          = $tagSearch['tag'];
      $partialOrders      = $tagSearch['matches'];
      $partialTitle       = 'Results';
      $partialExtraHeader = 'Tags';
      $partialExtraCell   = function ($o) use ($activeTag) {
          $tags = is_array($o['tags']) ? $o['tags'] : (array) ($o['tags'] ?? []);
          $html = '<div class="spot-matches">';
          foreach ($tags as $t) {
              $cls  = strtolower($t) === strtolower($activeTag) ? '' : 'spot-match-tag-sh';
              $html .= '<span class="spot-match-tag ' . $cls . '">' . esc($t) . '</span>';
          }
          return $html . '</div>';
      };
      require __DIR__ . '/partials/gql-orders-table.php';
    ?>
  <?php endif; ?>
<?php endif; ?>
