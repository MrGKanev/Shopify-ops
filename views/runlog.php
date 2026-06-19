<?= topbar('Run History', 'Recent audit and scan executions') ?>

<div class="table-wrap">
  <div class="table-header">
    <h2>Recent Runs</h2>
    <span><?= count($runLog) ?> stored run<?= count($runLog) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($runLog)): ?>
    <div class="empty p-6">
      <div class="icon">📋</div>
      <h3>No runs logged yet</h3>
      <p>Run an audit or scan to populate the operational history.</p>
    </div>
  <?php else: ?>
    <?= searchInput('tbl-runlog', 'Filter by tool, status, date, error…') ?>
    <table id="tbl-runlog">
      <thead>
        <tr>
          <th>Time</th>
          <th>Tool</th>
          <th>Status</th>
          <th>Period</th>
          <th>Duration</th>
          <th>Rows</th>
          <th>Scanned</th>
          <th>Error / Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($runLog as $entry):
          $status = $entry['status'] ?? '';
          $statusClass = match ($status) {
              'ok' => 'chip-paid',
              'issues_found' => 'chip-partial',
              default => 'chip-unpaid',
          };
          $meta = $entry['meta'] ?? [];
        ?>
        <tr>
          <td class="text-sm"><?= esc($entry['created_at'] ?? '') ?></td>
          <td class="font-mono text-sm"><?= esc($entry['tool'] ?? '') ?></td>
          <td><span class="chip <?= $statusClass ?>"><?= esc($status) ?></span></td>
          <td class="text-sm">
            <?php if (!empty($entry['start_date']) || !empty($entry['end_date'])): ?>
              <?= esc($entry['start_date'] ?? '') ?> → <?= esc($entry['end_date'] ?? '') ?>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <td><?= isset($entry['duration']) && $entry['duration'] !== null ? esc($entry['duration']) . 's' : '-' ?></td>
          <td><?= $entry['rows_found'] ?? '-' ?></td>
          <td><?= $entry['scanned'] ?? '-' ?></td>
          <td class="text-sm" style="max-width:360px;white-space:normal">
            <?php if (!empty($entry['error'])): ?>
              <span class="text-danger"><?= esc($entry['error']) ?></span>
            <?php elseif (!empty($meta)): ?>
              <?php foreach ($meta as $k => $v): ?>
                <span class="chip chip-unknown"><?= esc($k) ?>: <?= esc(is_bool($v) ? ($v ? 'yes' : 'no') : (string)$v) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
