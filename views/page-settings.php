<div class="topbar">
  <div>
    <h1>Settings</h1>
    <div class="meta">Configuration and connectivity</div>
  </div>
</div>

<div class="run-form" style="margin-bottom:1.5rem">
  <h2>API Connection Test</h2>
  <div class="hint">Sends a lightweight request to both APIs to verify credentials and reachability.</div>

  <form method="post">
    <input type="hidden" name="action" value="test_connection">
    <button class="btn" type="submit">Test connections</button>
  </form>

  <?php if ($connResults !== null): ?>
    <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:1.25rem">
      <?php foreach (['ss' => 'ShipStation', 'shopify' => 'Shopify'] as $key => $label):
        $r = $connResults[$key];
      ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;
                    background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.85rem 1.1rem;
                    border-left:3px solid <?= $r['ok'] ? 'var(--ok)' : 'var(--danger)' ?>">
          <div>
            <span style="font-weight:700;font-size:.9rem"><?= $label ?></span>
            <?php if ($r['error']): ?>
              <div style="font-size:.8rem;color:var(--danger);margin-top:.2rem"><?= esc($r['error']) ?></div>
            <?php elseif (!$r['ok']): ?>
              <div style="font-size:.8rem;color:var(--danger);margin-top:.2rem">HTTP <?= $r['code'] ?></div>
            <?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:.6rem">
            <?php if ($r['ms']): ?>
              <span style="font-size:.75rem;color:var(--muted)"><?= $r['ms'] ?> ms</span>
            <?php endif; ?>
            <span class="badge <?= $r['ok'] ? 'badge-ok' : 'badge-warn' ?>">
              <?= $r['ok'] ? 'Connected' : 'Failed' ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (isset($_GET['unbanned'])): ?>
  <div class="flash flash-ok" style="margin-bottom:1rem">✓ IP unbanned successfully.</div>
<?php endif; ?>

<div class="table-wrap" style="margin-bottom:1.5rem">
  <div class="table-header">
    <h2>Banned IPs</h2>
    <span><?= count($bannedIps) ?> active ban<?= count($bannedIps) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($bannedIps)): ?>
    <div class="empty" style="padding:1.5rem">
      <div class="icon">✅</div>
      <h3>No active bans</h3>
      <p>No IPs are currently locked out.</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>IP Address</th><th>Failed attempts</th><th>Banned until</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($bannedIps as $ip => $entry):
          $until = date('Y-m-d H:i', $entry['until']);
          $secs  = $entry['until'] - time();
          $days  = (int) floor($secs / 86400);
          $hours = (int) floor(($secs % 86400) / 3600);
          $remaining = $days > 0 ? "{$days}d {$hours}h left" : "{$hours}h left";
        ?>
        <tr>
          <td style="font-family:monospace"><?= esc($ip) ?></td>
          <td><?= (int) ($entry['count'] ?? 0) ?></td>
          <td><?= esc($until) ?> <span style="color:var(--muted);font-size:.8rem">(<?= esc($remaining) ?>)</span></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="unban_ip">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <button class="btn btn-sm btn-danger" type="submit"
                      onclick="return confirm('Unban <?= esc($ip) ?>?')">Unban</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="table-wrap">
  <div class="table-header"><h2>Current configuration</h2></div>
  <table>
    <thead><tr><th>Key</th><th>Value</th></tr></thead>
    <tbody>
      <?php
        $configKeys = [
          'SHOPIFY_STORE'        => getenv('SHOPIFY_STORE') ?: '-',
          'SHOPIFY_ACCESS_TOKEN' => getenv('SHOPIFY_ACCESS_TOKEN') ? '••••••••' : 'Not set',
          'SS_API_KEY'           => getenv('SS_API_KEY')    ? '••••••••' : 'Not set',
          'SS_API_SECRET'        => getenv('SS_API_SECRET') ? '••••••••' : 'Not set',
          'CACHE_TTL'            => (getenv('CACHE_TTL') ?: '14400') . ' s',
          'WEB_PASSWORD'         => getenv('WEB_PASSWORD')  ? '••••••••' : 'Not set',
        ];
        foreach ($configKeys as $k => $v):
          $missing = ($v === 'Not set');
      ?>
        <tr>
          <td style="font-family:monospace;font-size:.85rem"><?= esc($k) ?></td>
          <td style="font-size:.875rem;color:<?= $missing ? 'var(--danger)' : 'var(--muted)' ?>"><?= esc($v) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
