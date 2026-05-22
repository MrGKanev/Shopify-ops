<?= topbar('Tag Search', 'Find all orders with a specific Shopify tag') ?>

<?= featureInfoStart('tagsearch', 'Tag Search') ?>
  <p><strong>Tag Search</strong> finds all Shopify orders that carry a specific tag. The lookup uses Shopify's native indexed tag filter, so it is fast even on large stores.</p>
  <p>Useful for finding all orders in a batch, promotion, or custom workflow - for example <code>wholesale</code>, <code>vip</code>, <code>reorder</code>, or any internal routing tag.</p>
  <ul>
    <li>Match is <strong>exact and case-insensitive</strong> - partial matches are not supported.</li>
    <li>Date range is optional. Without it, the search covers all orders in the store.</li>
    <li>Results show financial status, fulfilment status, total, and all tags on each order.</li>
    <li>Results may be truncated for very broad searches - add a date range to narrow them.</li>
  </ul>

<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Search by tag</h2>
  <div class="hint">Exact tag match - case-insensitive. Optionally narrow results by date range.</div>

  <?php if ($tagSearchError): ?>
    <div class="error-msg mb-3"><?= esc($tagSearchError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="tag_search">
    <div class="date-row mb-3">
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
      <div class="mf-hint">No date range - searches all orders.</div>
      <button class="btn btn-submit-end" type="submit">Search</button>
    </div>
  </form>

  <?php if ($tagSearch !== null): ?>
    <div class="duration-note mt-4 mb-0">
      Tag <code><?= esc($tagSearch['tag']) ?></code>
      <?php if ($tagSearch['start'] || $tagSearch['end']): ?>
        &middot; <?= esc($tagSearch['start'] ?: '…') ?> → <?= esc($tagSearch['end'] ?: '…') ?>
      <?php endif; ?>
      &mdash; <strong><?= count($tagSearch['matches']) ?></strong> order<?= count($tagSearch['matches']) !== 1 ? 's' : '' ?> found
      <?php if ($tagSearch['truncated']): ?>
        <span class="source-badge cached">results truncated - set a date range</span>
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
