<?= topbar('Job Queue', 'Background audit jobs and worker status') ?>

<?php if (isset($_GET['queued'])): ?>
  <div class="flash flash-ok">Queued job <?= esc($_GET['queued']) ?>. Run <code>php worker.php --once</code> to process it.</div>
<?php endif; ?>
<?php if (isset($_GET['queue_error'])): ?>
  <div class="flash flash-err"><?= esc($_GET['queue_error']) ?></div>
<?php endif; ?>

<div class="run-form">
  <h2>Queue Audit</h2>
  <div class="hint">Creates a pending audit job without doing API work in the browser. Process pending jobs from CLI with <code>php worker.php --once</code>.</div>
  <form method="post">
    <input type="hidden" name="action" value="queue_audit">
    <?php dateRangePartial('audit', date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), '', 'Queue Audit') ?>
  </form>
</div>

<div class="table-wrap">
  <div class="table-header">
    <h2>Jobs</h2>
    <span><?= count($jobs) ?> job<?= count($jobs) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($jobs)): ?>
    <div class="empty p-6">
      <div class="icon">📋</div>
      <h3>No jobs yet</h3>
      <p>Queued audits will appear here.</p>
    </div>
  <?php else: ?>
    <?= searchInput('tbl-jobs', 'Filter by job id, type, status…') ?>
    <table id="tbl-jobs">
      <thead><tr><th>Queued</th><th>Job</th><th>Status</th><th>Started</th><th>Finished</th><th>Payload</th><th>Result</th></tr></thead>
      <tbody>
        <?php foreach ($jobs as $job):
          $status = $job['status'] ?? '';
          $chip = match ($status) {
              'done' => 'chip-paid',
              'running' => 'chip-partial',
              'failed' => 'chip-unpaid',
              default => 'chip-unknown',
          };
        ?>
        <tr>
          <td class="text-sm"><?= esc($job['queued_at'] ?? '') ?></td>
          <td>
            <div class="font-mono text-sm"><?= esc($job['id'] ?? '') ?></div>
            <div class="text-xs text-muted"><?= esc($job['label'] ?? $job['type'] ?? '') ?></div>
          </td>
          <td><span class="chip <?= $chip ?>"><?= esc($status) ?></span></td>
          <td class="text-sm"><?= esc($job['started_at'] ?: '-') ?></td>
          <td class="text-sm"><?= esc($job['finished_at'] ?: '-') ?></td>
          <td class="text-sm">
            <?php foreach (($job['payload'] ?? []) as $k => $v): ?>
              <span class="chip chip-unknown"><?= esc($k) ?>: <?= esc((string)$v) ?></span>
            <?php endforeach; ?>
          </td>
          <td class="text-sm">
            <?php if (!empty($job['error'])): ?>
              <span class="text-danger"><?= esc($job['error']) ?></span>
            <?php else: ?>
              <?php foreach (($job['result'] ?? []) as $k => $v): ?>
                <span class="chip chip-unknown"><?= esc($k) ?>: <?= esc((string)$v) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
