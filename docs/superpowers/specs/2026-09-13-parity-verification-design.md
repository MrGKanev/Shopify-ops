# Independent Laravel/legacy parity verification — design

Дата: **2026-09-13**.

## Проблем

`docs/laravel-platform-audit.md` и `docs/laravel-rewrite.md` твърдят **72/72**
feature parity и **115/115** legacy test файла "fully mapped" — без нито един
`Partial`/`Todo` ред. Тези документи са self-reported от предишни сесии, не са
доказани изпълнимо, и такъв 100%-навсякъде резултат е сам по себе си съмнителен.
Целта на тази работа е независима проверка, която не приема тези таблици за
вярно, докато не бъде доказана изпълнимо — и план legacy кодът да бъде
премахнат само след такова доказателство.

## Решение

1. Нов PHPUnit test suite, `tests/Parity/`, който сравнява legacy и Laravel
   логика директно чрез differential тестове върху споделени fixtures.
2. Нов доклад, `docs/parity-verification.md`, единственото място, което твърди
   "parity" — ред по инструмент, само след линкнат преминаващ тест.
3. Корекция на съществуващите `laravel-platform-audit.md`/`laravel-rewrite.md`
   редове in-place, когато differential тест докаже грешно `Done` твърдение —
   политика, обновена на 2026-09-14 след като и двата документа бяха
   изтрити изцяло, виж "Корекция на старите документи" по-долу.
4. Legacy кодът остава напълно недокоснат до финалния cutover (виж cutover
   правилата в `docs/laravel-uat-cutover-checklist.md`) — тази работа не
   трие нищо инкрементално.

## Компоненти

### `tests/Parity/`

- Живее в root test suite (не в `laravel/`), защото трябва да зарежда и
  legacy autoload, и Laravel domain класове.
- Bootstrap: `require` legacy `vendor/autoload.php` **и**
  `laravel/vendor/autoload.php` в един `phpunit.xml` testsuite entry — без да
  се boot-ва Laravel HTTP kernel/container. Само plain PHP domain класове.
- Fixture data се преизползва от съществуващите legacy fixtures в
  `tests/Unit` където вече съществуват; нови fixtures се пишат само когато
  липсват.
- Всеки differential тест:
  1. изгражда един споделен raw fixture (Shopify/ShipStation-shaped array);
  2. подава го през legacy pure-logic пътя (напр. `Comparator::findDuplicates()`);
  3. подава го през Laravel domain пътя (напр. `DuplicateOrderAnalyzer::analyze()`);
  4. асертва еквивалентни резултати — нормализира само козметични разлики
     (array key order), никога не нормализира истинска logic разлика.
- Където legacy логиката не е чист static метод (напр. `DuplicateOrderInsights`
  взима `Client` за pagination), тестът fake-ва мрежовата граница от двете
  страни, за да сравнява само бизнес логиката.
- Никога не се вика `audit.php`/`worker.php` директно и никога няма реална
  мрежова заявка — `.env` съдържа истински credentials за реален магазин.

### `docs/parity-verification.md`

Една таблица, един ред на инструмент (72 реда, същите ID-та като
`ToolRegistry`):

| Tool | Legacy reference | Laravel reference | Differential test | Verdict | Notes |
|---|---|---|---|---|---|

Verdict е едно от: `Verified match` / `Verified mismatch (fixed)` /
`Verified mismatch (open)` / `Not yet audited`. Ред получава verdict различен
от `Not yet audited` само след линкнат тест, който реално се изпълнява в CI.

### Корекция на старите документи (решение от 2026-09-13 до 2026-09-14)

До 2026-09-14: когато differential тест докаже грешно `Done` твърдение в
`laravel-platform-audit.md` или `laravel-rewrite.md`, съответният ред се
поправя on the spot (статус → `Partial`/`Todo` + какво точно липсва) с линк
към новото доказателство. Структурата на тези документи не се пренаписва —
само грешните твърдения.

**Обновено решение (2026-09-14):** и двата документа бяха изтрити изцяло по
изрично искане — self-reported "72/72 Done" claims нямаха стойност след като
независимата проверка започна многократно да ги опровергава, а поддържането
на два паралелни "източника на истина" (старите self-reported таблици и
`parity-verification.md`) създаваше риск от разминаване. Реалното, все още
валидно съдържание на тези документи (deployment/cutover процес, release
gate критерии, feature-parity легенда) беше преместено или преформулирано
inline в документите, които разчитаха на него: `docs/laravel-deployment-runbook.md`,
`docs/laravel-uat-cutover-checklist.md`, `docs/laravel-test-audit.md`,
`docs/laravel-todo.md`, `docs/parity-verification.md`, `README.md`,
`laravel/README.md`. `laravel/tests/Feature/RegistryConsistencyTest.php`
имаше тест, който четеше `laravel-rewrite.md` директно (`test_feature_tracker_counts_remain_complete`)
— премахнат, защото проверяваше точно оспорваната self-reported бройка.
Оттук нататък `docs/parity-verification.md` е единственото място, което
твърди feature parity, а `docs/laravel-todo.md` е единственият backlog за
отворени product decisions — и двата вече не се "извличат" от изтритите
документи, а са самостоятелни.

## Pilot (първа итерация)

Два инструмента, избрани защото единият е pure-function read-only, а другият
е mutating/high-risk:

- **Duplicate Detector** (`dupes`): legacy `Comparator::findDuplicates()`
  vs Laravel `DuplicateOrderAnalyzer`.
- **Push to ShipStation**: legacy `ShipStation::createOrder()` vs Laravel
  `PushOrderToShipStation`.

Pilot output: `tests/Parity/` scaffold, двата differential теста (passing или
разкриващи реален бъг), първите два реда в `parity-verification.md`, и — ако
някой тест докаже грешно "Done" твърдение — fix в Laravel кода плюс
коригиран ред в стария документ.

## Скалиране към всичките 72 инструмента

След одобрен pilot, работата продължава по risk order: mutating/
notification-triggering инструменти първи, после read-only reports — по
няколко на сесия. Всеки добавя differential тест + ред в
`parity-verification.md` + fix, ако е нужен.

**Известно ограничение (установено 2026-09-14):** harness-ът нарочно не
boot-ва Laravel container/DB (виж "Компоненти" по-горе) — това пази тестовете
бързи и напълно network-free, но означава, че всеки Laravel клас, чиято логика
реално минава през Eloquent заявки (не просто in-memory attribute casting
като `Store::resolved*Rules()`) или през `Http`/`Notification` facades, не
може да се извика директно в differential тест без допълнителна
инфраструктура. Установено конкретно за: `SaveOrderNote`/
`ShopifyAdminClient::updateOrderNote()` (иска `Http::fake()`, което иска
booted container) и `LoginThrottle` (всеки метод прави `LoginAttempt::where()`
заявка, включително `lockForUpdate()`/`DB::transaction()` — не просто четене
на един in-memory модел). И двата все още имат съответен legacy код
(`Shopify::updateOrderNote()`, `Auth::attempt()`/ban logic), но не могат да
получат verdict различен от `Not yet audited`, докато harness-ът не се
разшири да boot-ва поне DB connection (SQLite in-memory) — по-голяма промяна
в дизайна, не нещо за инкрементално решаване в рамките на един инструмент.
Забавящите константи в `LoginThrottle` (3 опита / 1 час window / 1 седмица
бан) бяха потвърдени идентични с `Auth.php` само чрез четене на кода, не чрез
изпълним тест — тази разлика (потвърдено чрез четене срещу потвърдено чрез
тест) трябва да остане видима в `parity-verification.md`, не да се смесва с
редовете, които реално минават през CI.

**Разширение (2026-09-14):** `config()` helper вече се поддържа. Класове като
`OrderTypeClassifier` четат настройки (`order_types.json`/`config/order-types.php`
— Z1/Z2 required items) през `config()`, не през constructor параметър, а
самият helper иска `app('config')` от контейнер. `tests/Parity/bootstrap.php`
вече bind-ва гол `Illuminate\Container\Container` + `Illuminate\Config\Repository`
само с `order-types` ключа — не `Illuminate\Foundation\Application`, не HTTP
kernel, не DB, не service providers. Това разширява harness-а достатъчно за
класове, които ползват `config()` като статичен settings store (същия дух като
"plain PHP domain класове"), но НЕ отключва нищо, което реално прави Eloquent
заявка или ползва `Http`/`Notification` facades — ограничението по-горе за
`SaveOrderNote`/`LoginThrottle` остава непроменено. Добавяй нов config ключ в
bootstrap.php само когато следващ тест реално го изисква, не превантивно.

## Финален cutover

Едва когато всеки ред е `Verified match` и двете production-like репетиции в
`docs/laravel-uat-cutover-checklist.md` реално са изпълнени (не само
документирани), се изпълнява единственият cutover: legacy PHP дървото се
трие, tag/branch се пази като архив — по процеса, описан в
"Irreversible cutover checklist" в `docs/laravel-uat-cutover-checklist.md`.
Legacy файлове не се пипат инкрементално преди тази точка.

## Извън обхвата

- Пренаписване на структурата на `docs/laravel-todo.md` — само корекция на
  грешни редове (`laravel-platform-audit.md`/`laravel-rewrite.md` бяха
  изтрити изцяло на 2026-09-14, виж "Корекция на старите документи" по-горе).
- Инкрементално триене на legacy файлове преди финалния cutover.
- Живи заявки към Shopify/ShipStation/SMTP/Slack/Discord/Google в който и да е
  differential тест.
- Нов dashboard/CLI tool за tracking — `docs/parity-verification.md` е обикновен
  markdown файл, поддържан ръчно.
