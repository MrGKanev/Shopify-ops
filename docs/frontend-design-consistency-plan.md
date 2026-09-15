# Design consistency plan

**Дата:** 2026-09-15. **Статус:** план, нищо от това не е приложено още.

## Находки (измерено, не предположение)

- **91 Blade view файла**, всеки хендкодва бутони/карти/таблици с Tailwind utility класове директно — няма нито един споделен Blade component.
- **13 различни "primary button" class stringа** (`bg-indigo-600` вариации: `rounded` vs `rounded-lg`, `px-4` vs `px-5`, `py-2` vs `py-2.5`, с/без `hover:`/`font-semibold`).
- **54 различни "card wrapper" class stringа** (`rounded-xl border...` комбинации).
- **`resources/css/app.css` има 2009 реда / 357 custom класа** (`.btn`, `.badge`, `.chip`, `.hub-card`, `.db-cache-*`, `.ignored-list`, и т.н.) — очевидно пренесени от legacy `assets/src/app.css`. От тях **само 4 реално се ползват в Blade** (`.btn`, `.table-wrap`, `.flash`, `.empty`) — hub-card/hub-grid/hub-section/badge/chip/ignored-list и стотици други са **мъртъв CSS**, нищо не ги вика.
- **~30 от 91 файла нямат нито един `dark:` клас** (предимно `resources/views/reports/*.blade.php`) — на dark theme тези страници ще покажат светли карти/таблици на тъмен фон, докато останалите 60 файла коректно потъмняват. Това е най-вероятно точно "грозното и странично", което видя.
- Dark mode механизмът е коректен и единен (`@custom-variant dark` в app.css бинд-ва Tailwind `dark:` към `[data-theme="dark"]`, същия атрибут, който старите `.btn`/`.chip` CSS правила ползват) — проблемът не е счупен toggle, а просто пропуснати класове на 1/3 от страниците.

**Извод:** няма нужда да се "връща" 357-класовата CSS библиотека — тя е мъртва и copy-paste от legacy. Реалната конвенция в проекта вече е Tailwind utility-first в Blade; просто липсва един споделен слой, затова всяка страница преоткрива бутона/картата си малко по-различно.

## Целеви подход

Малка библиотека **Blade anonymous components** (`resources/views/components/*.blade.php`) — нативен Laravel механизъм за преизползване, изграден върху съществуващите Tailwind класове, не нова система. Всеки компонент носи `dark:` вариантите **веднъж**, вместо всяка страница да ги помни поотделно.

Компоненти (покриват наблюдаваните разминавания, без излишни варианти):

| Компонент | Заменя |
|---|---|
| `<x-button>` (`variant="primary\|ghost\|danger"`, `size="sm\|md"`) | 13-те button варианта |
| `<x-card>` | 54-те card wrapper варианта |
| `<x-stat-tile>` | `db-card`-стил бройки (Dashboard, Trends) |
| `<x-data-table>` (slots: `head`, `body`, `empty`) | ръчно повтаряни `<table>`/`overflow-x-auto` обвивки |
| `<x-badge>` (`tone="ok\|warn\|danger\|info"`) | Hot/Recurring badge-овете, risk level badge-ове, status chip-ове |
| `<x-alert>` (`tone="ok\|error"`) | flash съобщения, "credentials required" банери |
| `<x-empty-state>` | "No X found" блоковете, различни на всяка страница |
| `<x-page-header>` (title + subtitle + slot за action бутони) | заглавната секция + breadcrumb, различна структура на всяка страница |

Всеки компонент се пише веднъж с `dark:` покритие — решава dark-mode пропуска автоматично за всяка страница, която мигрира към него.

## Фази

### Фаза 0 — Изграждане на компонентите (изолирано, нисък риск)

- Създай `resources/views/components/` с горните 8 компонента.
- Всеки компонент взима 1-2 реални употреби от текущите страници като base (напр. `<x-button>` primary = най-честия от 13-те варианта: `rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-500`).
- Мигрирай `layouts/app.blade.php` първо (вече ползва `.btn`/breadcrumb частично) — доказва компонентите работят преди да пипаш 90 други файла.
- Тест: `php artisan test` (feature тестовете проверяват текст/route-ове, не точни класове — би трябвало да останат зелени без промяна).

### Фаза 1 — Migration batch по директория (механично, паралелизируемо)

Ред по размер на печалба, не случаен:

1. **`resources/views/reports/` (~55 файла)** — най-голямата печалба, защото почти всички споделят една и съща форма (page header + filter form + results table + empty state). Най-вероятно 4-те компонента (`page-header`, `card`, `data-table`, `empty-state`) покриват 90% от всеки файл механично.
2. **`resources/views/saved-reports/`, `push-logs/`, `run-logs/`, `jobs/`, `print-queue/`, `ignored-orders/`** (~10 файла, тазгодишната ми сесийна работа — точно тук вкарах най-много ad-hoc utility класове набързо).
3. **`resources/views/orders/`, `customers/`, `metafields/`** (~15 файла).
4. **`resources/views/admin/`** (~15 файла).
5. **`resources/views/auth/`, `dashboard.blade.php`, `layouts/`** — последни, защото са най-видими/чувствителни (login, dashboard).

Всеки batch:
- Замени hand-rolled class strings с компонентите.
- Пусни `php artisan test --compact` (трябва да остане 759/759).
- Ръчна проверка (browser, светло + тъмно) на 1-2 представителни страници от batch-а, не всичките 91.

### Фаза 2 — Почистване

- Изтрий неизползваните ~350 CSS класа от `resources/css/app.css` (`.hub-card`, `.db-cache-*`, `.ignored-list`, `.chip-*` и т.н.) — мъртъв код, чисто изтриване, без риск щом compile/build мине чисто.
- Провери `pnpm build` минава и bundle размерът пада (2009-редовия CSS файл би трябвало да свие драстично).

### Фаза 3 — Guardrail

Едно изречение в `CLAUDE.md`/`AGENTS.md`: "UI: ползвай `resources/views/components/` (`<x-button>`, `<x-card>`, `<x-data-table>`, `<x-badge>`, `<x-alert>`, `<x-empty-state>`, `<x-page-header>`, `<x-stat-tile>`) — не хендкоденi нов Tailwind utility комбо за бутон/карта/таблица/badge, ако вече има компонент." Предотвратява връщане към същия проблем следващия път, когато някой (аз включително) добавя нова страница набързо.

## Обхват / какво НЕ влиза в плана

- Няма нова визуална посока (цветове/типография не се сменят) — целта е консистентност на СЪЩИЯ дизайн, не redesign.
- Няма промяна на JS/Alpine поведение, само markup/класове.
- 91 файла не се мигрират в един commit — по batch (Фаза 1 списъкът), за да остане diff-а прегледаем и тестваем на стъпки.
