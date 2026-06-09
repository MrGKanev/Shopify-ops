<?= topbar('Tag Audit', 'All unique tags used on orders - with frequency and last-seen date') ?>

<?= featureInfoStart('tagaudit', 'Tag Audit') ?>
  <p><strong>Tag Audit</strong> paginates through every order in the selected date range and builds a complete inventory of all tags in use - with usage frequency and the most recent order that carried each tag.</p>
  <p>Use this to audit tag hygiene: find tags that are no longer in use, spot typos or duplicates (<code>Wholesale</code> vs <code>wholesale</code>), and understand which workflow tags are most active.</p>
  <ul>
    <li>Results are sorted by <strong>frequency</strong> - most-used tags appear first.</li>
    <li>Each tag links to Tag Search so you can instantly see all orders with that tag.</li>
    <li>For large stores or long date ranges the scan may take 30–60 seconds as it pages through all orders.</li>
  </ul>

<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan tags</h2>
  <div class="hint">Paginates through all orders in the date range and aggregates every tag found. Large stores may take a moment.</div>

  <?php if ($tagAuditError): ?>
    <div class="error-msg mb-3"><?= esc($tagAuditError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="tag_audit">
    <?php dateRangePartial('ta', $taStart, $taEnd) ?>
  </form>

  <?php if ($tagAuditResult !== null): ?>
    <div class="duration-note mt-4 mb-0">
      Scanned <strong><?= $tagAuditResult['total_orders'] ?></strong> orders
      (<?= esc($tagAuditResult['start']) ?> → <?= esc($tagAuditResult['end']) ?>)
      &mdash; <strong><?= count($tagAuditResult['tags']) ?></strong> unique tag<?= count($tagAuditResult['tags']) !== 1 ? 's' : '' ?>
      <?php if ($tagAuditResult['truncated']): ?>
        <span class="source-badge cached">results truncated - narrow the date range</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($tagAuditResult !== null): ?>
  <?php if (empty($tagAuditResult['tags'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">🏷️</div>
        <h3>No tags found</h3>
        <p>No orders have any tags in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <?php
      $cutoffDate = date('Y-m-d', strtotime('-90 days'));
    ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Tag usage</h2>
        <span><?= count($tagAuditResult['tags']) ?> unique tags</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Tag</th>
            <th>Orders</th>
            <th>Last seen</th>
            <th>Last order</th>
            <th>Search</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tagAuditResult['tags'] as $row):
            $isOrphan = $row['count'] === 1 && $row['last_date'] < $cutoffDate;
          ?>
          <tr class="<?= $isOrphan ? 'opacity-55' : '' ?>">
            <td>
              <code><?= esc($row['tag']) ?></code>
              <?php if ($isOrphan): ?>
                <span class="source-badge cached" title="Used only once, more than 90 days ago">orphan</span>
              <?php endif; ?>
            </td>
            <td><?= $row['count'] ?></td>
            <td><?= esc($row['last_date']) ?></td>
            <td>
              <?php if ($row['last_order']): ?>
                <?= esc($row['last_order']) ?>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="?page=tagsearch" onclick="sessionStorage.setItem('prefillTag', <?= json_encode($row['tag']) ?>); return true;"
                 class="btn btn-ghost btn-sm">Search</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
(function() {
  var tag = sessionStorage.getItem('prefillTag');
  if (tag) {
    sessionStorage.removeItem('prefillTag');
    var input = document.querySelector('input[name="tag_input"]');
    if (input) input.value = tag;
  }
})();
</script>
