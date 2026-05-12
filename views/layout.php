<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($appTitle) ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>
  (function() {
    if (localStorage.getItem('theme') === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  })();
</script>
</head>
<body>

<!-- Mobile header (hidden on desktop) -->
<header class="mobile-header">
  <div class="brand">
    <?php if ($appLogo): ?>
      <img src="<?= esc($appLogo) ?>" alt="<?= esc($appBrand) ?>" class="mobile-logo">
    <?php else: ?>
      <?= esc($appBrand) ?> <span style="color:var(--muted);font-weight:400;font-size:.75rem"><?= esc($shopifyStore) ?></span>
    <?php endif; ?>
  </div>
  <button class="hamburger" id="js-hamburger" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
</header>

<!-- Overlay behind open sidebar -->
<div class="sidebar-overlay" id="js-overlay"></div>

<div class="layout">

  <aside class="sidebar" id="js-sidebar">
    <div class="sidebar-header">
      <?php if ($appLogo): ?>
        <img src="<?= esc($appLogo) ?>" alt="<?= esc($appBrand) ?>" class="sidebar-logo">
      <?php else: ?>
        <div class="brand"><?= esc($appBrand) ?></div>
      <?php endif; ?>
      <div class="store"><span style="text-transform:uppercase;letter-spacing:.05em;font-size:.65rem;opacity:.6">Store</span> <?= esc($shopifyStore) ?></div>
    </div>

    <div class="sidebar-section">Tools</div>
    <ul class="sidebar-nav">
      <li>
        <a href="?" class="<?= $page === 'reports' ? 'page-active' : '' ?>">Reports</a>
      </li>
      <li>
        <a href="?page=run" class="<?= $page === 'run' ? 'page-active' : '' ?>">Run Audit</a>
      </li>
      <li>
        <a href="?page=trends" class="<?= $page === 'trends' ? 'page-active' : '' ?>">Trends</a>
      </li>
      <li>
        <a href="?page=spotcheck" class="<?= $page === 'spotcheck' ? 'page-active' : '' ?>">Spot-check</a>
      </li>
    </ul>
    <div class="sidebar-section">Manage</div>
    <ul class="sidebar-nav">
      <li>
        <a href="?page=ignored" class="<?= $page === 'ignored' ? 'page-active' : '' ?>">
          Ignored
          <?php if (count($ignoredOrders) > 0): ?>
            <span class="badge badge-warn" style="font-size:.65rem"><?= count($ignoredOrders) ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li>
        <a href="?page=pushlog" class="<?= $page === 'pushlog' ? 'page-active' : '' ?>">
          Push Log
          <?php if (count($pushLog) > 0): ?>
            <span class="badge badge-ok" style="font-size:.65rem"><?= count($pushLog) ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li>
        <a href="?page=settings" class="<?= $page === 'settings' ? 'page-active' : '' ?>">Settings</a>
      </li>
    </ul>

    <?php if (!empty($reports)): ?>
      <div class="sidebar-section">History</div>
      <ul class="sidebar-nav">
        <?php foreach ($reports as $r): ?>
          <li class="history-item">
            <a href="?date=<?= esc($r['date']) ?>"
               class="<?= ($page === 'reports' && $r['date'] === $selectedDate) ? 'active' : '' ?>">
              <span class="date-label"><?= esc($r['date']) ?></span>
              <?= badge($r['count']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="sidebar-footer">
      <button class="btn btn-ghost btn-sm btn-full" id="js-theme-toggle" style="margin-bottom:.5rem" type="button">
        <span id="js-theme-icon">🌙</span> Dark mode
      </button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost btn-sm btn-full" type="submit">Sign out</button>
      </form>
      <div style="margin-top:.75rem;text-align:center">
        <a href="https://github.com/mrgkanev/ShipStation-Shopify-Checker"
           target="_blank" rel="noopener"
           style="font-size:.7rem;color:var(--muted);text-decoration:none;opacity:.6"
           title="View on GitHub">
          ⌥ GitHub
        </a>
      </div>
    </div>
  </aside>

  <main class="main">
    <?php
      $allowedPages = ['reports', 'run', 'trends', 'spotcheck', 'ignored', 'pushlog', 'settings'];
      $page         = in_array($page, $allowedPages, true) ? $page : 'reports';
      $pageFile     = __DIR__ . '/page-' . $page . '.php';
      if (file_exists($pageFile)) {
          require $pageFile;
      } else {
          require __DIR__ . '/page-reports.php';
      }
    ?>
  </main>

</div>

<script src="assets/app.js"></script>

<!-- Dry-run preview modal -->
<div id="preview-modal" style="display:none;position:fixed;inset:0;z-index:1000;
     background:rgba(0,0,0,.55);align-items:center;justify-content:center">
  <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;
              width:min(700px,95vw);max-height:85vh;display:flex;flex-direction:column;
              box-shadow:0 8px 32px rgba(0,0,0,.35)">
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:.9rem 1.1rem;border-bottom:1px solid #e2e8f0;
                background:#f8fafc;border-radius:10px 10px 0 0">
      <strong id="preview-title" style="font-size:.95rem;color:#1e293b">Preview payload</strong>
      <button onclick="document.getElementById('preview-modal').style.display='none'"
              style="background:none;border:none;font-size:1.2rem;cursor:pointer;
                     color:#64748b;line-height:1">&times;</button>
    </div>
    <pre id="preview-body"
         style="margin:0;padding:1rem;overflow:auto;font-size:.78rem;
                line-height:1.5;flex:1;white-space:pre-wrap;word-break:break-all;
                background:#ffffff;color:#1e293b">Loading…</pre>
    <div style="padding:.75rem 1.1rem;border-top:1px solid #e2e8f0;
                font-size:.78rem;color:#64748b;background:#f8fafc;border-radius:0 0 10px 10px">
      This is the payload that <em>would</em> be sent to ShipStation — nothing has been pushed yet.
    </div>
  </div>
</div>

<script>
function previewPush(shopifyId, orderLabel) {
  var modal = document.getElementById('preview-modal');
  var body  = document.getElementById('preview-body');
  var title = document.getElementById('preview-title');
  title.textContent = 'Preview payload — ' + orderLabel;
  body.textContent  = 'Loading…';
  modal.style.display = 'flex';

  var fd = new FormData();
  fd.append('action',     'preview_push');
  fd.append('shopify_id', shopifyId);

  fetch('', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.error) {
        body.textContent = 'Error: ' + data.error;
      } else {
        body.textContent = JSON.stringify(data.payload, null, 2);
      }
    })
    .catch(function(e) { body.textContent = 'Request failed: ' + e; });
}

document.getElementById('preview-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>
