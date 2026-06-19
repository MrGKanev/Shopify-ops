<?= topbar('Config Check', 'Validate local JSON configuration files') ?>

<div class="table-wrap">
  <div class="table-header">
    <h2>Validation Results</h2>
    <span><?= count($configResults) ?> file<?= count($configResults) !== 1 ? 's' : '' ?></span>
  </div>
  <table>
    <thead><tr><th>File</th><th>Present</th><th>Status</th><th>Issues / Notes</th></tr></thead>
    <tbody>
      <?php foreach ($configResults as $result): ?>
      <tr>
        <td class="font-mono text-sm"><?= esc($result['file']) ?></td>
        <td><?= $result['present'] ? 'Yes' : 'No' ?></td>
        <td><span class="chip <?= $result['ok'] ? 'chip-paid' : 'chip-unpaid' ?>"><?= $result['ok'] ? 'OK' : 'Needs attention' ?></span></td>
        <td class="text-sm">
          <?php if (!empty($result['issues'])): ?>
            <ul>
              <?php foreach ($result['issues'] as $issue): ?><li><?= esc($issue) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (!empty($result['notes'])): ?>
            <?php foreach ($result['notes'] as $note): ?><div class="text-muted"><?= esc($note) ?></div><?php endforeach; ?>
          <?php endif; ?>
          <?php if (empty($result['issues']) && empty($result['notes'])): ?>
            <span class="text-muted">No issues found.</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
