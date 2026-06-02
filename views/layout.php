<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= esc($appTitle) ?><?= $appStoreNumber ? ' - #' . esc($appStoreNumber) : '' ?></title>
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

<div id="js-loading-bar"></div>

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
      <div class="sidebar-header-top">
        <?php if ($appLogo): ?>
          <img src="<?= esc($appLogo) ?>" alt="<?= esc($appBrand) ?>" class="sidebar-logo">
        <?php else: ?>
          <div class="brand"><?= esc($appBrand) ?></div>
        <?php endif; ?>
        <button class="theme-icon-btn" id="js-theme-toggle" type="button" title="Toggle theme">
          <span id="js-theme-icon">🌙</span>
        </button>
      </div>
      <div class="store"><span class="store-label">Store</span> <?= esc($shopifyStore) ?></div>
      <?php if (!empty($allStores) && count($allStores) > 1): ?>
        <form method="post" class="store-switcher">
          <input type="hidden" name="action" value="switch_store">
          <select name="store_id" class="store-select" onchange="this.form.submit()" title="Switch store">
            <?php foreach ($allStores as $s): ?>
              <option value="<?= esc($s['id']) ?>" <?= (($s['id'] ?? '') === ($storeId ?? '')) ? 'selected' : '' ?>>
                <?= esc($s['label'] ?? $s['shopify_store'] ?? $s['id']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>

    <?php
      $auditPages  = ['hub-audit', 'reports', 'run', 'trends', 'dupes', 'refunds', 'addrcheck', 'emailcheck', 'orphans', 'hvorders', 'repeatrefunds', 'failedship', 'addrchanges', 'orderedits', 'bundlecheck'];
      $searchPages = ['hub-search', 'spotcheck', 'metafields', 'tagsearch', 'tagaudit', 'customer', 'tracking', 'compare', 'timeline', 'globalsearch', 'packingslip'];
      $managePages = ['ignored', 'pushlog'];
      $groupOf = function(string $p) use ($auditPages, $searchPages, $managePages): string {
          if (in_array($p, $auditPages,  true)) return 'audit';
          if (in_array($p, $searchPages, true)) return 'search';
          if (in_array($p, $managePages, true)) return 'manage';
          return 'settings';
      };
      $activeGroup = $groupOf($page);

      $pageTitles = [
          'reports' => 'Reports', 'run' => 'Run Audit', 'trends' => 'Trends',
          'dupes' => 'Duplicate Detector', 'refunds' => 'Refunds Tracker',
          'addrcheck' => 'Address Scanner', 'emailcheck' => 'Email Checker',
          'orphans' => 'Orphan Detector', 'hvorders' => 'High-Value No Phone',
          'repeatrefunds' => 'Repeat Refunds', 'failedship' => 'Voided Shipments',
          'addrchanges' => 'Address Changes',
          'orderedits'  => 'Order Edit History',
          'bundlecheck' => 'Bundle Check',
          'spotcheck' => 'Spot-check', 'metafields' => 'Metafields',
          'tagsearch' => 'Tag Search', 'tagaudit' => 'Tag Audit',
          'customer' => 'Customer Lookup', 'tracking' => 'Tracking Feed',
          'compare'   => 'Order Compare',
          'timeline'     => 'Order Timeline',
          'globalsearch' => 'Global Search',
          'packingslip'  => 'Packing Slip Preview',
          'ignored' => 'Ignored Orders', 'pushlog' => 'Push Log',
      ];
      $hubPages = ['hub-audit', 'hub-search'];
    ?>

    <nav class="flat-nav">

      <a href="?page=hub-audit" class="flat-nav-link <?= $activeGroup === 'audit'  ? 'active' : '' ?>" title="Audit">
        <span class="flat-nav-icon">📋</span><span class="nav-label"> Audit</span>
      </a>
      <a href="?page=hub-search" class="flat-nav-link <?= $activeGroup === 'search' ? 'active' : '' ?>" title="Search &amp; Lookup">
        <span class="flat-nav-icon">🔎</span><span class="nav-label"> Search &amp; Lookup</span>
      </a>
      <a href="?page=ignored" class="flat-nav-link <?= $activeGroup === 'manage'  ? 'active' : '' ?>" title="Manage">
        <span class="flat-nav-icon">📂</span><span class="nav-label"> Manage</span>
        <?php if (count($ignoredOrders) > 0): ?>
          <span class="badge badge-warn badge-sm nav-badge" style="margin-left:auto"><?= count($ignoredOrders) ?></span>
        <?php endif; ?>
      </a>
      <a href="?page=settings" class="flat-nav-link <?= $page === 'settings' ? 'active' : '' ?>" title="Settings">
        <span class="flat-nav-icon">⚙</span><span class="nav-label"> Settings</span>
      </a>

    </nav>

    <div class="sidebar-search">
      <form method="get" action="">
        <input type="hidden" name="page" value="globalsearch">
        <input type="text" name="q" class="sidebar-search-input"
               placeholder="Search order #…"
               value="<?= esc(($page === 'globalsearch') ? ($_GET['q'] ?? '') : '') ?>"
               autocomplete="off" spellcheck="false">
      </form>
    </div>

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
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost btn-sm btn-full sidebar-signout-btn" type="submit">Sign out</button>
      </form>
      <div class="sidebar-footer-row">
        <a href="https://github.com/MrGKanev/Shopify-ops"
           class="sidebar-github" target="_blank" rel="noopener" title="View on GitHub">
          Shopify Ops v1.3.0
        </a>
        <button class="sidebar-collapse-btn" id="js-sidebar-collapse" title="Collapse sidebar" type="button">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
        </button>
      </div>
    </div>
  </aside>

  <main class="main">
    <?php
      $allowedPages = ['hub-audit', 'hub-search', 'reports', 'run', 'trends', 'dupes', 'refunds', 'addrcheck', 'emailcheck', 'orphans', 'hvorders', 'repeatrefunds', 'failedship', 'addrchanges', 'orderedits', 'bundlecheck', 'spotcheck', 'tracking', 'compare', 'timeline', 'metafields', 'tagsearch', 'tagaudit', 'customer', 'ignored', 'pushlog', 'settings', 'globalsearch', 'packingslip'];
      $page         = in_array($page, $allowedPages, true) ? $page : 'hub-audit';
      $pageFile     = __DIR__ . '/' . $page . '.php';

      // Breadcrumb — shown on tool pages (not hub or settings)
      if (!in_array($page, $hubPages, true) && $page !== 'settings' && isset($pageTitles[$page])) {
          $hubLink  = $activeGroup === 'search' ? '?page=hub-search' : '?page=hub-audit';
          $hubLabel = $activeGroup === 'search' ? 'Search &amp; Lookup' : 'Audit';
          if ($activeGroup === 'manage') { $hubLink = '?page=ignored'; $hubLabel = 'Manage'; }
          echo '<div class="breadcrumb"><a href="' . $hubLink . '">' . $hubLabel . '</a>'
             . '<span class="breadcrumb-sep">›</span>'
             . '<span>' . esc($pageTitles[$page]) . '</span></div>';
      }

      if (file_exists($pageFile)) {
          require $pageFile;
      } else {
          require __DIR__ . '/hub-audit.php';
      }
    ?>
  </main>

</div>

<div id="toast-container"></div>
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
      <span>This is the payload that <em>would</em> be sent to ShipStation - nothing has been pushed yet.</span>
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
  title.textContent = 'Preview payload - ' + orderLabel;
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
