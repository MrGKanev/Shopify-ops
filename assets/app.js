// Local timezone for cache expiry timestamps
document.querySelectorAll('.js-localtime').forEach(function(el) {
  var ts = parseInt(el.dataset.ts, 10);
  if (!ts) return;
  el.textContent = new Date(ts * 1000).toLocaleString([], {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit'
  });
});

// Mobile sidebar toggle
(function() {
  var sidebar  = document.getElementById('js-sidebar');
  var overlay  = document.getElementById('js-overlay');
  var hamburger = document.getElementById('js-hamburger');
  if (!sidebar || !overlay || !hamburger) return;

  function open() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  hamburger.addEventListener('click', function() {
    sidebar.classList.contains('open') ? close() : open();
  });
  overlay.addEventListener('click', close);

  // Close sidebar on nav link click (mobile)
  sidebar.querySelectorAll('a').forEach(function(a) {
    a.addEventListener('click', close);
  });
})();

// Audit loading overlay
(function() {
  var form    = document.getElementById('js-audit-form');
  var overlay = document.getElementById('js-audit-loading');
  if (!form || !overlay) return;

  var stepIds = ['lstep-shopify', 'lstep-ss', 'lstep-compare'];
  var current = 0;
  var timer;

  function activateStep(i) {
    if (i > 0) {
      var prev = document.getElementById(stepIds[i - 1]);
      if (prev) { prev.classList.remove('active'); prev.classList.add('done'); }
    }
    var el = document.getElementById(stepIds[i]);
    if (el) el.classList.add('active');
  }

  function cycle() {
    if (current < stepIds.length) {
      activateStep(current);
      current++;
      // cycle through steps every ~8s while waiting for server
      timer = setTimeout(cycle, 8000);
    } else {
      // all steps "done", keep last one active so it doesn't go blank
      var last = document.getElementById(stepIds[stepIds.length - 1]);
      if (last) { last.classList.remove('active'); last.classList.add('done'); }
    }
  }

  form.addEventListener('submit', function() {
    overlay.classList.add('active');
    cycle();
    // Keep overlay up through navigation - beforeunload fires when response arrives
    window.addEventListener('beforeunload', function() {
      clearTimeout(timer);
      // leave overlay visible so there's no flash before the new page paints
    });
  });
})();

// Search / filter (coordinates with type-filter if present)
function applyTableFilters(targetId) {
  var tbody  = document.querySelector('#' + targetId);
  if (!tbody) return;
  var search = document.querySelector('.js-search[data-target="' + targetId + '"]');
  var type   = document.querySelector('.js-type-filter[data-target="' + targetId + '"]');
  var q      = search ? search.value.toLowerCase() : '';
  var t      = type   ? type.value.toLowerCase()   : '';
  tbody.querySelectorAll('tr').forEach(function(tr) {
    var text      = tr.textContent.toLowerCase();
    var typeChip  = tr.querySelector('td .chip[class*="chip-type-"]');
    var typeText  = typeChip ? typeChip.textContent.trim().toLowerCase() : '';
    var matchText = !q || text.includes(q);
    var matchType = !t || typeText === t;
    tr.style.display = (matchText && matchType) ? '' : 'none';
  });
}

document.querySelectorAll('.js-search').forEach(function(input) {
  if (!document.querySelector('#' + input.dataset.target)) return;
  input.addEventListener('input', function() { applyTableFilters(input.dataset.target); });
});

document.querySelectorAll('.js-type-filter').forEach(function(sel) {
  if (!document.querySelector('#' + sel.dataset.target)) return;
  sel.addEventListener('change', function() { applyTableFilters(sel.dataset.target); });
});

// Bulk select helpers
function updateBulkBar(barId) {
  var bar     = document.getElementById('bar-' + barId);
  var counter = document.getElementById('cnt-' + barId);
  if (!bar) return;
  var checked = document.querySelectorAll('[data-bar="' + barId + '"].js-row-check:checked').length;
  if (counter) counter.textContent = checked + ' selected';
  bar.classList.toggle('bulk-bar-active', checked > 0);
}

// Select-all checkboxes
document.querySelectorAll('.js-select-all').forEach(function(master) {
  master.addEventListener('change', function() {
    var tbody  = document.getElementById(master.dataset.target);
    var barId  = master.dataset.bar;
    if (!tbody) return;
    tbody.querySelectorAll('.js-row-check').forEach(function(cb) {
      cb.checked = master.checked;
    });
    updateBulkBar(barId);
  });
});

// Dark mode toggle
(function() {
  var btn  = document.getElementById('js-theme-toggle');
  var icon = document.getElementById('js-theme-icon');
  if (!btn) return;

  function apply(dark) {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    if (icon) icon.textContent = dark ? '☀️' : '🌙';
    btn.querySelector('span + span') || (btn.lastChild.textContent = dark ? ' Light mode' : ' Dark mode');
    btn.childNodes.forEach(function(n) {
      if (n.nodeType === 3) n.textContent = dark ? ' Light mode' : ' Dark mode';
    });
  }

  var isDark = localStorage.getItem('theme') === 'dark';
  apply(isDark);

  btn.addEventListener('click', function() {
    isDark = !isDark;
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    apply(isDark);
  });
})();

// Inline ignore form toggle
document.querySelectorAll('.js-ignore-toggle').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var form = document.getElementById('ignore-form-' + btn.dataset.order);
    if (form) form.style.display = form.style.display === 'none' ? 'flex' : 'none';
  });
});


// ── Metafields page ──────────────────────────────────────────────────────────
function fillSearch(ns, key) {
  var nsEl  = document.getElementById('js-search-ns');
  var keyEl = document.getElementById('js-search-key');
  if (nsEl)  nsEl.value  = ns;
  if (keyEl) keyEl.value = key;
  var form = document.getElementById('js-mf-search-form');
  if (form) { form.scrollIntoView({ behavior: 'smooth', block: 'center' }); keyEl.focus(); }
  var filter = document.getElementById('js-mf-filter');
  if (filter) filter.value = ns + '.' + key;
}

// ── Sidebar collapsible groups ────────────────────────────────────────────────
(function() {
  var groups = document.querySelectorAll('.nav-group');
  if (!groups.length) return;

  function getItems(group) {
    return group.querySelector('.nav-group-items');
  }

  function openGroup(group, animate) {
    var items = getItems(group);
    if (!items) return;
    group.classList.add('open');
    // Set exact height so transition is smooth — no jump
    items.style.height = items.scrollHeight + 'px';
    if (!animate) {
      // Instant open on page load (no transition flash)
      items.style.transition = 'none';
      requestAnimationFrame(function() { items.style.transition = ''; });
    }
  }

  function closeGroup(group) {
    var items = getItems(group);
    if (!items) return;
    // Pin current height before collapsing so transition starts from the right value
    items.style.height = items.scrollHeight + 'px';
    requestAnimationFrame(function() {
      items.style.height = '0';
      group.classList.remove('open');
    });
  }

  // Which group contains the currently active page
  var currentActiveGroup = '';
  groups.forEach(function(g) {
    if (g.querySelector('.page-active')) currentActiveGroup = g.dataset.group;
  });

  // Restore saved open/closed state — but only when still in the same active group.
  // If the user navigated to a different section, discard saved state so stale
  // groups don't keep popping open.
  var saved = {};
  if (localStorage.getItem('navActiveGroup') === currentActiveGroup) {
    try { saved = JSON.parse(localStorage.getItem('navGroups') || '{}'); } catch(e) {}
  }

  function saveState() {
    var state = {};
    groups.forEach(function(g) { state[g.dataset.group] = g.classList.contains('open'); });
    localStorage.setItem('navGroups',      JSON.stringify(state));
    localStorage.setItem('navActiveGroup', currentActiveGroup);
  }

  function toggleGroup(group) {
    group.classList.contains('open') ? closeGroup(group) : openGroup(group, true);
    saveState();
  }

  groups.forEach(function(group) {
    var items = getItems(group);
    if (items) items.style.height = '0';

    var isActive = group.dataset.group === currentActiveGroup;
    if (isActive || saved[group.dataset.group]) openGroup(group, false);

    var toggle = group.querySelector('.nav-group-toggle');
    if (toggle) toggle.addEventListener('click', function() { toggleGroup(group); });
  });
})();
