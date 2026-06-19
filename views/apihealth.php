<?= topbar('API Health', 'Shopify and ShipStation connectivity, scopes and API version') ?>

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
        <?php $scopeCheckOk = (bool)($h['checks']['scopes']['ok'] ?? false); ?>
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
