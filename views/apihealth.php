<?= topbar('API Health', 'Shopify and ShipStation connectivity, scopes and API version') ?>

<?php
  $flowSummary = $shopifyFlowHealth['summary'] ?? ['total' => 0, 'healthy' => 0, 'attention' => 0, 'never_run' => 0];
  $flows = $shopifyFlowHealth['flows'] ?? [];
?>

<div class="table-wrap mb-6">
  <div class="table-header">
    <h2>Shopify Flow Monitor</h2>
    <span>
      <?= (int)$flowSummary['total'] ?> flows ·
      <?= (int)$flowSummary['healthy'] ?> recent OK ·
      <?= (int)$flowSummary['attention'] ?> need attention ·
      <?= (int)$flowSummary['never_run'] ?> never run
    </span>
  </div>

  <?php if (empty($flows)): ?>
    <div class="empty p-6">
      <div class="icon">📋</div>
      <h3>No Shopify flows configured</h3>
      <p>Flow status will appear here after the app catalog is loaded.</p>
    </div>
  <?php else: ?>
    <?= searchInput('tbl-shopify-flows', 'Filter by flow, area, status, error…') ?>
    <table id="tbl-shopify-flows">
      <thead>
        <tr>
          <th>Area</th>
          <th>Flow</th>
          <th>Dependency</th>
          <th>Last run</th>
          <th>Status</th>
          <th>Runs</th>
          <th>Last error</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($flows as $flow):
          $status = $flow['status'] ?? 'never_run';
          $statusClass = match ($status) {
              'ok' => 'chip-paid',
              'issues_found' => 'chip-partial',
              'never_run' => 'chip-unknown',
              default => 'chip-unpaid',
          };
          $statusLabel = match ($status) {
              'ok' => 'OK',
              'issues_found' => 'Issues found',
              'validation_error' => 'Validation error',
              'config_error' => 'Config error',
              'error' => 'Error',
              'never_run' => 'Never run',
              default => (string)$status,
          };
          $latest = $flow['latest'] ?? [];
          $lastError = $flow['last_error'] ?? [];
        ?>
          <tr>
            <td class="text-sm"><?= esc($flow['area'] ?? '') ?></td>
            <td>
              <a href="?page=<?= esc($flow['page'] ?? '') ?>"><?= esc($flow['label'] ?? '') ?></a>
              <div class="text-xs text-muted font-mono"><?= esc($flow['tool'] ?? '') ?></div>
            </td>
            <td class="text-sm"><?= esc($flow['dependency'] ?? '') ?></td>
            <td class="text-sm">
              <?= !empty($flow['last_run_at']) ? esc($flow['last_run_at']) : '-' ?>
              <?php if (isset($latest['duration']) && $latest['duration'] !== null): ?>
                <div class="text-xs text-muted"><?= esc($latest['duration']) ?>s</div>
              <?php endif; ?>
            </td>
            <td><span class="chip <?= $statusClass ?>"><?= esc($statusLabel) ?></span></td>
            <td class="text-sm">
              <?= (int)($flow['runs'] ?? 0) ?>
              <?php if (($flow['errors'] ?? 0) > 0): ?>
                <div class="text-xs text-danger"><?= (int)$flow['errors'] ?> error<?= (int)$flow['errors'] === 1 ? '' : 's' ?></div>
              <?php endif; ?>
            </td>
            <td class="text-sm" style="max-width:420px;white-space:normal">
              <?php if (!empty($flow['error_message'])): ?>
                <span class="text-danger"><?= esc($flow['error_message']) ?></span>
                <div class="text-xs text-muted"><?= esc($flow['last_error_at'] ?? '') ?></div>
              <?php elseif ($status === 'issues_found'): ?>
                <span class="text-muted"><?= esc($latest['rows_found'] ?? 0) ?> row<?= (int)($latest['rows_found'] ?? 0) === 1 ? '' : 's' ?> found</span>
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

<div class="run-form">
  <h2>Health Check</h2>
  <div class="hint">Runs live lightweight checks against Shopify and ShipStation. Shopify requested version: <code><?= esc(Shopify::API_VERSION) ?></code>.</div>
  <form method="post">
    <input type="hidden" name="action" value="refresh_api_health">
    <button class="btn" type="submit">Refresh API Health</button>
  </form>
</div>

<?php if ($apiHealth !== null): ?>
  <div class="duration-note mb-4">Checked at <?= esc($apiHealth['checked_at']) ?></div>

  <?php foreach (['shopify' => 'Shopify', 'shipstation' => 'ShipStation'] as $key => $label):
    $h = $apiHealth[$key];
  ?>
    <div class="table-wrap mb-6">
      <div class="table-header">
        <h2><?= esc($label) ?></h2>
        <span class="badge <?= ($h['ok'] ?? false) ? 'badge-ok' : 'badge-warn' ?>"><?= ($h['ok'] ?? false) ? 'Healthy' : 'Needs attention' ?></span>
      </div>

      <?php if (!empty($h['error'])): ?>
        <div class="error-msg m-3"><?= esc($h['error']) ?></div>
      <?php endif; ?>

      <?php if ($key === 'shopify'): ?>
        <?php $scopeCheckOk = (bool)($h['checks']['graphql']['ok'] ?? false); ?>
        <table>
          <tbody>
            <tr><th>Requested API version</th><td><?= esc($h['requested_version'] ?? '') ?></td></tr>
            <tr><th>Returned API version</th><td><?= esc($h['returned_version'] ?: '-') ?></td></tr>
            <tr><th>Shop name</th><td><?= esc($h['shop_name'] ?: '-') ?></td></tr>
            <tr><th>Scopes</th><td><?= esc(implode(', ', $h['scopes'] ?? []) ?: '-') ?></td></tr>
            <tr>
              <th>Missing required scopes</th>
              <td>
                <?php if (!$scopeCheckOk): ?>
                  <span class="text-danger">Unable to verify (scopes check failed)</span>
                <?php elseif (empty($h['missing_scopes'])): ?>
                  None
                <?php else: ?>
                  <span class="text-danger"><?= esc(implode(', ', $h['missing_scopes'])) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>
      <?php endif; ?>

      <table class="mt-3">
        <thead><tr><th>Check</th><th>HTTP</th><th>Latency</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach (($h['checks'] ?? []) as $name => $check): ?>
          <tr>
            <td><?= esc($name) ?></td>
            <td><?= esc($check['code'] ?? 0) ?></td>
            <td><?= esc($check['ms'] ?? 0) ?> ms</td>
            <td><span class="chip <?= ($check['ok'] ?? false) ? 'chip-paid' : 'chip-unpaid' ?>"><?= ($check['ok'] ?? false) ? 'OK' : 'Failed' ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
