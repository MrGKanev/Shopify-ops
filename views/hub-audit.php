<?= topbar('Audit', 'Run audits, scan for issues and track missing orders') ?>

<?php
$groups = ToolRegistry::hubSections('audit');
?>

<?php foreach ($groups as $label => $tools): ?>
<div class="hub-section">
  <div class="hub-section-title"><?= esc($label) ?></div>
  <div class="hub-grid">
    <?php foreach ($tools as $t): ?>
      <a href="?page=<?= esc($t['page']) ?>" class="hub-card">
        <div class="hub-card-icon"><?= $t['icon'] ?></div>
        <div class="hub-card-name"><?= esc($t['name']) ?></div>
        <div class="hub-card-desc"><?= esc($t['desc']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
