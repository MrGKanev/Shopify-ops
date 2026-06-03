<?= topbar('Settings', 'Configuration and connectivity') ?>

<div class="run-form">
  <h2>API Connection Test</h2>
  <div class="hint">Sends a lightweight request to both APIs to verify credentials and reachability.</div>

  <form method="post">
    <input type="hidden" name="action" value="test_connection">
    <button class="btn" type="submit">Test connections</button>
  </form>

  <?php if ($connResults !== null): ?>
    <div class="flex flex-col gap-3 mt-5">
      <?php foreach (['ss' => 'ShipStation', 'shopify' => 'Shopify'] as $key => $label):
        $r = $connResults[$key];
      ?>
        <div class="conn-result <?= $r['ok'] ? 'conn-ok' : 'conn-err' ?>">
          <div>
            <span class="font-bold text-[.9rem]"><?= $label ?></span>
            <?php if ($r['error']): ?>
              <div class="text-xs text-danger mt-0.5"><?= esc($r['error']) ?></div>
            <?php elseif (!$r['ok']): ?>
              <div class="text-xs text-danger mt-0.5">HTTP <?= $r['code'] ?></div>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-2">
            <?php if ($r['ms']): ?>
              <span class="text-xs text-muted"><?= $r['ms'] ?> ms</span>
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
  <div class="flash flash-ok">✓ IP unbanned successfully.</div>
<?php endif; ?>

<div class="table-wrap mb-6">
  <div class="table-header">
    <h2>Banned IPs</h2>
    <span><?= count($bannedIps) ?> active ban<?= count($bannedIps) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($bannedIps)): ?>
    <div class="empty p-6">
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
          <td class="font-mono"><?= esc($ip) ?></td>
          <td><?= (int) ($entry['count'] ?? 0) ?></td>
          <td><?= esc($until) ?> <span class="text-xs text-muted">(<?= esc($remaining) ?>)</span></td>
          <td>
            <form method="post" class="inline">
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
          'CACHE_TTL'            => (function() {
              $s = (int)(getenv('CACHE_TTL') ?: 82800);
              if ($s >= 86400) return round($s / 86400, 1) . ' days (' . $s . ' s)';
              if ($s >= 3600)  return round($s / 3600, 1)  . ' h ('    . $s . ' s)';
              return $s . ' s';
          })(),
          'CACHE_RETENTION'      => (function() {
              $s = (int)(getenv('CACHE_RETENTION') ?: 1209600);
              if ($s >= 86400) return round($s / 86400, 1) . ' days (' . $s . ' s)';
              if ($s >= 3600)  return round($s / 3600, 1)  . ' h ('    . $s . ' s)';
              return $s . ' s';
          })(),
          'WEB_PASSWORD'         => getenv('WEB_PASSWORD')  ? '••••••••' : 'Not set',
        ];
        foreach ($configKeys as $k => $v):
          $missing = ($v === 'Not set');
      ?>
        <tr>
          <td class="font-mono text-sm"><?= esc($k) ?></td>
          <td class="text-sm <?= $missing ? 'text-danger' : 'text-muted' ?>"><?= esc($v) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
