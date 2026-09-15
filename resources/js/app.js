document.querySelectorAll('[data-print-window]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});

const sidebar = document.getElementById('js-sidebar');
const overlay = document.getElementById('js-overlay');
const themeIcon = document.getElementById('js-theme-icon');
if (localStorage.getItem('theme') === 'dark') document.documentElement.dataset.theme = 'dark';
document.getElementById('js-hamburger')?.addEventListener('click', () => { sidebar?.classList.toggle('open'); overlay?.classList.toggle('open'); });
overlay?.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('open'); });
document.getElementById('js-sidebar-collapse')?.addEventListener('click', () => { sidebar?.classList.toggle('collapsed'); localStorage.setItem('sidebar-collapsed', sidebar?.classList.contains('collapsed') ? '1' : '0'); });
if (localStorage.getItem('sidebar-collapsed') === '1') sidebar?.classList.add('collapsed');
const syncThemeIcon = () => { if (themeIcon) themeIcon.textContent = document.documentElement.dataset.theme === 'dark' ? '☀️' : '🌙'; };
syncThemeIcon();
document.getElementById('js-theme-toggle')?.addEventListener('click', () => { const dark = document.documentElement.dataset.theme !== 'dark'; if (dark) document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme; localStorage.setItem('theme', dark ? 'dark' : 'light'); syncThemeIcon(); });
document.querySelector('[data-store-switcher] select')?.addEventListener('change', (event) => { event.currentTarget.form.action = event.currentTarget.value; event.currentTarget.form.submit(); });

const commandPalette = document.querySelector('[data-command-palette]');
const commandInput = commandPalette?.querySelector('[data-command-palette-input]');
const commandResults = commandPalette?.querySelector('[data-command-palette-results]');
let commandItems = [];
let activeCommandIndex = 0;
let commandRequest;
let commandSearchTimer;

const renderCommandResults = () => {
    if (!commandResults) return;
    commandResults.replaceChildren();
    if (commandItems.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'command-palette-empty';
        empty.textContent = 'No matching pages, issues or runs.';
        commandResults.append(empty);
        return;
    }
    commandItems.forEach((item, index) => {
        const link = document.createElement('a');
        link.href = item.url;
        link.className = `command-palette-result${index === activeCommandIndex ? ' active' : ''}`;
        link.setAttribute('role', 'option');
        link.setAttribute('aria-selected', index === activeCommandIndex ? 'true' : 'false');
        link.innerHTML = '<span><strong></strong><small></small></span><em></em>';
        link.querySelector('strong').textContent = item.label;
        link.querySelector('small').textContent = item.description;
        link.querySelector('em').textContent = item.kind;
        link.addEventListener('mouseenter', () => {
            if (activeCommandIndex !== index) {
                activeCommandIndex = index;
                renderCommandResults();
            }
        });
        commandResults.append(link);
    });
};

const loadCommandResults = async () => {
    if (!commandInput) return;
    commandRequest?.abort();
    commandRequest = new AbortController();
    try {
        const response = await fetch(`${commandInput.dataset.endpoint}?q=${encodeURIComponent(commandInput.value)}`, {
            headers: { Accept: 'application/json' },
            signal: commandRequest.signal,
        });
        if (!response.ok) throw new Error('Search failed');
        commandItems = (await response.json()).results;
        activeCommandIndex = 0;
        renderCommandResults();
    } catch (error) {
        if (error.name !== 'AbortError') {
            commandItems = [];
            renderCommandResults();
        }
    }
};

const openCommandPalette = () => {
    if (!commandPalette || !commandInput) return;
    commandPalette.hidden = false;
    document.body.classList.add('command-palette-open');
    commandInput.value = '';
    commandInput.focus();
    loadCommandResults();
};

const closeCommandPalette = () => {
    if (!commandPalette) return;
    commandPalette.hidden = true;
    document.body.classList.remove('command-palette-open');
};

document.querySelectorAll('[data-command-palette-open]').forEach((button) => button.addEventListener('click', openCommandPalette));
commandPalette?.querySelectorAll('[data-command-palette-close]').forEach((button) => button.addEventListener('click', closeCommandPalette));
commandInput?.addEventListener('input', () => {
    window.clearTimeout(commandSearchTimer);
    commandSearchTimer = window.setTimeout(loadCommandResults, 150);
});
commandInput?.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' && commandItems.length) { event.preventDefault(); activeCommandIndex = (activeCommandIndex + 1) % commandItems.length; renderCommandResults(); }
    if (event.key === 'ArrowUp' && commandItems.length) { event.preventDefault(); activeCommandIndex = (activeCommandIndex - 1 + commandItems.length) % commandItems.length; renderCommandResults(); }
    if (event.key === 'Enter' && commandItems[activeCommandIndex]) { event.preventDefault(); window.location.assign(commandItems[activeCommandIndex].url); }
});
document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); commandPalette?.hidden ? openCommandPalette() : closeCommandPalette(); }
    if (event.key === 'Escape' && commandPalette && !commandPalette.hidden) closeCommandPalette();
});
