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
      <?= esc($appBrand) ?> <span class="header-store"><?= esc($shopifyStore) ?></span>
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
      <div class="store"><span class="store-label">Store</span> <?= esc($shopifyStore) ?></div>
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
            <span class="badge badge-warn badge-sm"><?= count($ignoredOrders) ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li>
        <a href="?page=pushlog" class="<?= $page === 'pushlog' ? 'page-active' : '' ?>">
          Push Log
          <?php if (count($pushLog) > 0): ?>
            <span class="badge badge-ok badge-sm"><?= count($pushLog) ?></span>
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
      <button class="btn btn-ghost btn-sm btn-full" id="js-theme-toggle" type="button">
        <span id="js-theme-icon">🌙</span> Dark mode
      </button>
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost btn-sm btn-full" type="submit">Sign out</button>
      </form>
      <a href="https://github.com/mrgkanev/ShipStation-Shopify-Checker"
         class="sidebar-github" target="_blank" rel="noopener" title="View on GitHub">
        ⌥ GitHub
      </a>
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
<div id="preview-modal">
  <div class="preview-dialog">
    <div class="preview-header">
      <strong id="preview-title">Preview payload</strong>
      <button class="preview-close"
              onclick="document.getElementById('preview-modal').style.display='none'">&times;</button>
    </div>
    <pre id="preview-body">Loading…</pre>
    <div class="preview-footer">
      <span>This is the payload that <em>would</em> be sent to ShipStation — nothing has been pushed yet.</span>
      <button id="preview-copy-btn" class="preview-copy-btn" onclick="copyPreviewPayload()">Copy JSON</button>
    </div>
  </div>
</div>

<script>
function previewPush(shopifyId, orderLabel) {
  var modal = document.getElementById('preview-modal');
  var body  = document.getElementById('preview-body');
  var title = document.getElementById('preview-title');
  var btn   = document.getElementById('preview-copy-btn');
  title.textContent = 'Preview payload — ' + orderLabel;
  body.textContent  = 'Loading…';
  btn.textContent   = 'Copy JSON';
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

function copyPreviewPayload() {
  var text = document.getElementById('preview-body').textContent;
  var btn  = document.getElementById('preview-copy-btn');
  navigator.clipboard.writeText(text).then(function() {
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = 'Copy JSON'; }, 2000);
  }).catch(function() {
    btn.textContent = 'Failed';
    setTimeout(function() { btn.textContent = 'Copy JSON'; }, 2000);
  });
}

document.getElementById('preview-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>
