<?= topbar('Action Log', 'Operator actions performed in the dashboard') ?>

<div class="table-wrap">
  <div class="table-header">
    <h2>Actions</h2>
    <span><?= count($actionLog) ?> event<?= count($actionLog) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($actionLog)): ?>
    <div class="empty p-6">
      <div class="icon">📋</div>
      <h3>No actions logged yet</h3>
      <p>Ignore, push, queue, settings, and access-control actions will appear here.</p>
    </div>
  <?php else: ?>
    <?= searchInput('tbl-actionlog', 'Filter by action, IP, detail…') ?>
    <table id="tbl-actionlog">
      <thead><tr><th>Time</th><th>Action</th><th>IP</th><th>Details</th></tr></thead>
      <tbody>
        <?php foreach ($actionLog as $entry): ?>
        <tr>
          <td class="text-sm"><?= esc($entry['at'] ?? '') ?></td>
          <td><span class="chip chip-unknown"><?= esc($entry['action'] ?? '') ?></span></td>
          <td class="font-mono text-sm"><?= esc($entry['ip'] ?? '') ?></td>
          <td class="text-sm">
            <?php foreach (($entry['details'] ?? []) as $k => $v): ?>
              <span class="chip chip-unknown"><?= esc($k) ?>: <?= esc(is_scalar($v) ? (string)$v : json_encode($v)) ?></span>
            <?php endforeach; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
