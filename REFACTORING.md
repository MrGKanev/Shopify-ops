# Оптимизация и уеднаквяване на кода

Места, където кодът още се повтаря или е непоследователен, и как да ги обединим, за да е по-лесна поддръжката. Статусите по-долу са проверени по текущата работна директория на 2026-10-04; промените още не са комитнати. Описанията „Преди“ пазят изходното състояние и мотивацията.

> Свързани файлове: [ROADMAP-NEW-TOOLS.md](ROADMAP-NEW-TOOLS.md)

## Статус на изпълнението

- [ ] **§1 — общ report контролер + ReportDefinition:** остава; отделните контролери още са налични.
- [x] **§2 — един регистър:** `config/reports.php` + `ReportRegistry` захранват routes, навигация, tool catalog и `docs:tools`; има тестове.
- [x] **§3 — общи Blade компоненти:** всичките 46 отчета ползват `x-report.layout`; 1 895 реда вместо 3 103. Формите и резултатите са изнесени в компоненти; `x-alert` поддържа HTML, `x-data-table` — `columns`, `rows` и празно състояние. Формите още не се генерират от ReportDefinition (§1).
- [x] **§4 — разделяне на Shopify клиента:** транспорт, `OrderSearch`, GraphQL файлове, обща пагинация, nested connections и payment normalizer са изнесени. `ShopifyAdminClient` е тънък адаптер (253 реда) към обвързания с магазин `ShopifyClient` (765 реда; целта под ~700 за реализацията остава).
- [x] **Остатък към §4:** всички application actions и контролери инжектират нужния малък интерфейс (`ShopifyOrders`, `ShopifyCatalog`, `ShopifyCustomers`, `ShopifyPayments`; health/REST — `ShopifyHealth`/`ShopifyTransport`). Интерфейсите могат да се заменят независимо в тестове. Общият throttle е реализиран в `IntegrationThrottle`: 38 ShipStation заявки/60 s по API ключ и Shopify бюджет по магазин, с повторение на `THROTTLED`. Scheduled audit-ите имат `ThrottlesExceptions`.
- [x] **§5 — обща конвенция за HTTP клиентите:** и двете интеграции имат `ClientFactory::forStore()`, общи timeout-и/user agent и йерархия `IntegrationException` → `RateLimited`/`Unauthorized`/`UnexpectedResponse`. Отчетите показват безопасна конкретна причина; контекстът съдържа магазина и инструмента при queued run.
- [x] **§6 — един ReportResult:** всички report actions, контролери, изгледи и export-и използват `ReportResult { rows, scanned, pages, truncated, params, meta }`. Запазването е криптиран и компресиран JSON; миграцията преобразува старите резултати без зареждане на DTO класове и премахва `scanned_metric`/`rows_metric`.
- [x] **§7 — value object за правилата за нотификации:** `AsNotificationRules` връща immutable `ChatRules`/`EmailRules` с `RowRule`/`EmailRule.matches()`. `ReportNotifier` и digest-ът използват типовете; формите и activity log преобразуват с `toArray()`. Старият JSON формат и defaults са запазени.
- [x] **§8 — дребни подобрения:** лимитът от 500 run logs е премахнат; остава изтриването по възраст през `ops:prune-data`. Legacy config коментарът е премахнат. Съобщенията в контролерите използват преводи. PHPStan е ниво 7 и включва `app/Http`, `app/Models`, `app/Jobs`, без baseline; всички 221 съществуващи проблема са поправени. Общата матрица за достъп покрива 46 отчета; повторените проверки са преместени от 33 теста. Voided Shipments тегли веднъж и брои входните пратки.
- [x] **UI бъгът с 2FA е поправен:** `auth/two-factor-settings.blade.php` използва `tone="ok"`; има тест за страницата с включен 2FA и recovery codes.

**Обща проверка:** `php artisan test --compact` — 1059 успешни теста / 5 687 assertions (35 нови случая за §4–§6 и 71 за §7–§8/UI); след финалните промени по текстовете засегнатите 20 теста също минават; `composer analyse` — 0 грешки. Config и route кешове не бяха активни.

## Изходни повторения (преди §2–§4)

| Област | Сега | След рефактора (приблизително) |
| --- | --- | --- |
| Report контролери (`app/Http/Controllers/Reports`) | 46 файла, ~2 500 реда, почти еднакви | 1 общ контролер + 45 малки дефиниции (~20 реда всяка) |
| Report изгледи (`resources/views/reports`) | 46 файла, ~3 100 реда, еднаква форма / съобщения / export | Общ layout + компоненти; изгледът съдържа само специфичната таблица |
| Регистри на инструментите | 3 ръчно поддържани списъка (`tool-catalog.php`, `audit-hub.php`, `docs/tools.md`) + списъкът с контролери в `routes/web.php` | 1 регистър |
| `ShopifyAdminClient` | ~1 900 реда, 57 повторения на „unexpected response shape“, ~35 почти еднакви `*Candidates` метода | Query обекти + 1 метод за пагинация и проверка |
| Result DTO-та | 31 `*Result` класа + `ScanResult` с 12 nullable полета | 1 `ReportResult` |

---

## 1. Общ report контролер + ReportDefinition

**Сега.** Всички отчети вече минават през `QueuedReportRunner` (опашка, запазен резултат, export без ново сканиране), но всеки контролер още повтаря едно и също: `create` / `store` / `export`, `context()`, `configurationError()`, `viewData()`, извикването на runner-а и пренасочването. Разликите са само: tool key, правилата за валидация, действието, нужните креденшъли, CSV колоните и стойностите по подразбиране. Съобщенията за липсващи креденшъли се различават от отчет до отчет, а в изгледите са твърдо зададени.

**Решение.**

```php
// app/Application/Reports/Definitions/ShipmentAgingReport.php
final class ShipmentAgingReport extends ReportDefinition
{
    public string $tool = 'shipment_aging';
    public Integration $requires = Integration::ShipStation;
    public string $view = 'reports.shipment-aging';
    public string $action = RunShipmentAgingReport::class;

    public function rules(): array { return ['threshold' => ['required', 'integer', 'min:1', 'max:365']]; }
    public function defaults(): array { return ['threshold' => 3]; }
    public function arguments(array $params): array { return [$params['threshold']]; }

    /** @return array<string, Closure(array): scalar> */
    public function columns(): array
    {
        return ['Order' => fn ($r) => $r['order_number'], 'Date' => fn ($r) => $r['order_date'], 'Days' => fn ($r) => $r['days'], /* ... */];
    }
}
```

- Един `ReportController` (`show`, `run`, `result`, `export`) прави валидацията, проверката на креденшълите и извикването на `QueuedReportRunner` — веднъж.
- `columns()` се ползва и за CSV, и за `<x-data-table>`, така че таблицата и експортът не могат да се разминат.
- Сложните отчети (Run Audit, Customer LTV) могат да запазят собствен контролер.
- Миграция по един отчет; route имената (`reports.shipment-aging`, `.store`, `.result`, `.export`) остават същите, така че изгледите и тестовете не се чупят.

**Полза.** Нов отчет = 1 дефиниция + 1 анализатор + 1 изглед с таблица.

## 2. Един регистър на инструментите

**Преди.** Routes за отчетите вече се генерират от един списък slug → контролер в `routes/web.php`. Но добавянето на отчет още изисква ръчни промени в `config/tool-catalog.php` (email rules), `config/audit-hub.php` (навигация) и `docs/tools.md` (маркерът казва AUTO-GENERATED, а коментарът — „maintained by hand“).

**Решение.** `config/reports.php` (или `App\Application\Reports\ReportRegistry`) с `key`, `slug`, `label`, `section`, `description`, контролер/дефиниция и нужни креденшъли. От него:
- routes (списъкът в `routes/web.php` става цикъл по регистъра);
- навигацията и `tool-catalog` стават изгледи над регистъра;
- `php artisan docs:tools` пренаписва секцията между маркерите в `docs/tools.md`;
- тест: всеки `tool` от `run_logs` / `report_runs` съществува в регистъра.

## 3. Blade изгледи на отчетите

**Преди.** Всеки от 46-те изгледа повтаря: форма с дати (същите Tailwind класове), `@error('export')`, съобщение за креденшъли, съобщение за грешка, заглавие с брой, export форма със скрити полета, предупреждение за отрязани резултати, `@forelse` таблица. Текстовете са твърдо зададени на английски.

**Решение.** Компоненти (по правилото в AGENTS.md — разширяваме съществуващите `x-*`, не правим паралелни):
- `<x-report.layout :definition :result>` — заглавие, статуси, export, отрязани резултати; slot за таблицата;
- `<x-report.date-range-form>` / `<x-report.params-form :fields>` — генерирани от `rules()` / `defaults()`;
- `<x-data-table>` получава `:columns` (от дефиницията) + slot за ред по избор;
- общ partial за bulk-ignore чекбоксите (повтаря се в 5 изгледа).

Преди `x-alert` escape-ваше съдържанието си. Вече поддържа HTML в slot-а, включително списъци.

Промяна по споделен компонент се проверява на един отчет с период (On-Hold Stall) и един с параметри (Shipment Aging).

**Полза.** ~3 100 → ~1 200 реда; преводът на български става на едно място; еднакъв UX.

## 4. ShopifyAdminClient (~1 900 реда)

**Преди.** ~35 метода `*Candidates` със същия скелет: heredoc GraphQL → search низ → `paginateGraphql(..., 100)` → foreach с проверка `is_array($edge['node'])` и `throw new ShopifyGraphqlException([], '... unexpected response shape.')` (57 пъти) → `orderNormalizer->normalize`. Клиентът е едновременно HTTP транспорт, каталог от заявки и нормализатор.

**Решение.**
1. **Транспорт** (`ShopifyGraphqlTransport`): `graphql()`, `paginateGraphql()`, общ throttle (`IntegrationThrottle`), API версия.
2. **Помощен метод** `paginateOrderNodes(Store $store, string $query, OrderSearch $search, int $maxPages = 100): array` — проверява формата на edge-а веднъж, нормализира и връща `{orders, pages, truncated}`.
3. **`OrderSearch` value object:** `OrderSearch::created($store, $start, $end)->paid()->unfulfilled()->toString()` вместо ръчно сглобени низове. Границите на деня вече идват от `dayStart` / `dayEnd` (часовата зона на магазина) — те минават вътре в обекта.
4. **GraphQL файлове:** заявките в `resources/graphql/shopify/*.graphql` (или в отделни query класове), за да се четат, diff-ват и проверяват срещу схемата при смяна на версията.
5. **Разделяне на интерфейса** `ShopifyAdminGateway` на `ShopifyOrders`, `ShopifyCatalog`, `ShopifyCustomers`, `ShopifyPayments`. Имплементацията може да остане един клас; по-малките интерфейси улесняват fake-овете в тестовете и бъдещия `MirroredShopifyGateway` ([ROADMAP #1](ROADMAP-NEW-TOOLS.md)).

**Полза.** Клиентът пада под ~700 реда; нов отчет = една заявка + един ред за търсенето.

## 5. HTTP клиенти — една конвенция

**Изпълнено.** `ShopifyClientFactory::forStore()` и `ShipStationClientFactory::forStore()` връщат клиенти с обвързан магазин. Тесните Shopify contracts остават application границата; адаптерът делегира към фабриката. `ConfiguresIntegrationRequests` уеднаквява connect timeout (3 s), timeout (15 s), user agent и класификацията на HTTP грешките. GET retry и липсата на retry за mutations/POST са запазени. `userMessage()` не показва сурови upstream съобщения.

**Преди.** Shopify: един singleton клиент, `Store` се подава на всеки метод. ShipStation: `ShipStationClientFactory::forStore()` връща клиент, обвързан с ключовете и часовата зона на магазина. Retry логиката (включително `Retry-After` / `X-Rate-Limit-Reset`) вече е обща в `RetriesTransientRequests`, но timeout-ите, user agent-ът и изключенията се различават.

**Решение.** Една конвенция: `XClientFactory::forStore(Store): XClient` и за двете интеграции. Обща йерархия на изключенията (`IntegrationException` → `RateLimited`, `Unauthorized`, `UnexpectedResponse`), за да може страницата на отчета да покаже точна причина („изчерпан лимит, опитайте след 40 s“) вместо общото „report could not be completed“. `Context` (store_id, tool) в логовете на клиента.

## 6. Result DTO-та

**Изпълнено.** Старите report DTO-та са заменени с един `ReportResult`; входните стойности са в `params`, обобщенията и броячите по източник — в `meta`. Историята отчита директно `scanned` и `count(rows)`. Миграцията `standardize_report_run_results` запазва старите криптирани резултати, преобразува ги в JSON и премахва старите metric колони. Има тестове за преобразуването, float/null/Unicode стойности, криптиране, queue result/export и безопасни HTTP грешки.

**Преди.** 31 `*Result` класа + `ScanResult` с 12 nullable полета (`threshold`, `minimum`, `minimumEmails`, `skippedMissingCountry`, `days`, `totalVariants`…), сбор от полетата на всички отчети. `report_runs` пазят резултата като сериализиран PHP обект, а броячите за run history се взимат по име на поле (`scanned`, `products`, `orders`, `shopifyTotal`, `count:rows`, `count:pairs`…), защото всеки резултат ги нарича различно.

**Решение.** Един `ReportResult { rows, scanned, pages, truncated, params, meta }`. Специфичните стойности отиват в `params` (входните) и `meta` (обобщенията като `bySku`, `byType`). Редовете остават масиви (анализаторите не се пипат), с array-shape PHPDoc в дефиницията. Тогава резултатът се пази като JSON вместо сериализиран обект, а `scanned_metric` / `rows_metric` в `report_runs` отпадат.

`spatie/laravel-data` би дал типизирани редове, но е голяма промяна за малко полза → **не препоръчвам** засега.

## 7. Правилата за нотификации като value object

**Изпълнено.** Custom cast `AsNotificationRules` чете стария JSON и връща readonly обекти `ChatRules` и `EmailRules`. Праговете/defaults/mentions/получателите се нормализират във value object-ите; решенията за изпращане използват `matches()`. `Store` вече не съдържа `resolved*Rules()` логика. Четенето на defaults не променя съхранения JSON. Тестове покриват null/defaults, прагове, disabled/zero настройки, стар JSON, запис на обекти, immediate/digest доставка и формите.

**Преди.** Логиката е обединена в `ReportNotifier`, а Slack/Discord класовете са слети. Правилата обаче още са нетипизирани JSON масиви в `stores` (`slack_rules`, `discord_rules`, `email_rules`), които `Store` разчита с три `resolved*Rules()` метода.

**Решение.** Custom cast `AsNotificationRules` с value object-и (`ChatRules`, `EmailRule`) и метод `matches(int $rows)`. `Store` остава само с отношенията и креденшълите, а `ReportNotifier` проверява `$rules->scan->matches($rows)` вместо масиви.

## 8. Дребни, но полезни

**Изпълнено.** Всички точки от таблицата са адресирани. Всички 221 съществуващи type проблема са поправени; PHPStan ниво 7 минава без baseline и без потискане на грешки. Общият `ReportAccessTest` проверява login/operator границите за create/store/result/export/queue, когато маршрутът съществува, по целия регистър. Специфичните controller сценарии остават в техните тестове.

| Проблем | Решение |
| --- | --- |
| `RecordRun` трие стари `run_logs` с `skip(500)->take(500)` при **всяко** записване | Изтриване по възраст (`ops:prune-data` вече го прави) — лимитът в `RecordRun` може да отпадне |
| Коментарите в config-а говорят за „legacy“ (`ToolRegistry::HUBS`, `triggerCatalog()`) | Миграцията е завършена → премахване на препратките |
| Твърдо зададени текстове в контролерите (`'The CSV export could not be completed.'`) | Ключове в `lang/` (помага и за превода) |
| PHPStan ниво 5, без `app/Http`, `app/Models`, `app/Jobs` | Да се добавят пътищата и ниво 6 → 7 без baseline |
| 72 controller feature теста, които проверяват едни и същи сценарии | Един параметризиран тест по регистъра + тестове само за специфичното поведение |
| `VoidedShipmentsController` записва `scanned = count(rows)` | Истинският брой сканирани пратки в резултата (заедно с §6) |

---

## Ред на изпълнение (без счупени функционалности)

1. **§2 регистър — готово.** Route имената са запазени.
2. **§1 остава; §3 е готово.** Мигриране на отчетите по групи (първо тези с период от дати, после останалите). Всеки PR: дефиниция → изтриване на контролера → изгледът минава на компонентите → тестовете остават зелени.
3. **§6 — готово.** Всички report резултати използват един DTO и криптиран JSON.
4. **§4–§5 — готово.** Малки интерфейси и фабрики по магазин; общият throttle и middleware за временни грешки на scheduled audit-ите също са готови.
5. **§7, §8 — готово.** Типизирани правила, локализирани съобщения, общи тестове за достъп и PHPStan ниво 7 без baseline.

След всяка стъпка: `vendor/bin/pint --dirty --format agent`, `php artisan test --compact` за засегнатите тестове, `composer analyse`.

## Пакети

| Пакет | За | Препоръка |
| --- | --- | --- |
| — (вградено в Laravel) | Custom casts, `Http::macro`, `Context` | **Да** — покрива почти всичко тук |
| `spatie/laravel-data` | Типизирани DTO | Не засега |
| `saloonphp/saloon` | Обща структура на API клиентите | Не засега — §5 постига същото без пренаписване |
