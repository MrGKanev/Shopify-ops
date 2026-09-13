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
