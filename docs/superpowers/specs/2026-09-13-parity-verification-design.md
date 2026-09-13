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
   редове in-place, когато differential тест докаже грешно `Done` твърдение.
4. Legacy кодът остава напълно недокоснат до финалния cutover (Фаза 9 в
   `laravel-rewrite.md`) — тази работа не трие нищо инкрементално.

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

### Корекция на старите документи

Когато differential тест докаже грешно `Done` твърдение в
`laravel-platform-audit.md` или `laravel-rewrite.md`, съответният ред се
поправя on the spot (статус → `Partial`/`Todo` + какво точно липсва) с линк
към новото доказателство. Структурата на тези документи не се пренаписва —
само грешните твърдения.

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

## Финален cutover

Едва когато всеки ред е `Verified match` и двете production-like репетиции в
`docs/laravel-uat-cutover-checklist.md` реално са изпълнени (не само
документирани), се изпълнява единственият cutover: legacy PHP дървото се
трие, tag/branch се пази като архив — по вече написания процес във Фаза 9/10
на `laravel-rewrite.md`. Legacy файлове не се пипат инкрементално преди тази
точка.

## Извън обхвата

- Пренаписване на структурата на `laravel-platform-audit.md`/
  `laravel-rewrite.md`/`laravel-todo.md` — само корекция на грешни редове.
- Инкрементално триене на legacy файлове преди финалния cutover.
- Живи заявки към Shopify/ShipStation/SMTP/Slack/Discord/Google в който и да е
  differential тест.
- Нов dashboard/CLI tool за tracking — `docs/parity-verification.md` е обикновен
  markdown файл, поддържан ръчно.
