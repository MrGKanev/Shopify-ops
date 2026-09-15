# Laravel rewrite — отворени задачи

Последно обновяване: **2026-09-15** — cutover изпълнен на repo ниво (legacy PHP
изтрит, Laravel преместен в корена). Виж
[`docs/parity-verification.md`](parity-verification.md#2026-09-14-cutover-decision-session-closed)
за пълния 24/24 decision log. Оставащата "Production/infra решения" и "Процес"
секции по-долу са реални production действия (rehearsals, TLS/proxy, SMTP),
извън scope-а на repo restructuring-а — не са изпълнени и остават отворени.

Списък със самостоятелни отворени product-decision задачи, всяка от които не
изисква преработка на съседен код. По-старите self-reported "72/72 Done"
одитни документи (`laravel-platform-audit.md`, `laravel-rewrite.md`) бяха
премахнати на 2026-09-14 — техните твърдения не бяха доказани изпълнимо и в
няколко реда бяха грешни (виж по-долу). Единственото място, което вече
твърди feature parity, е [`docs/parity-verification.md`](parity-verification.md)
(изисква линкнат, реално изпълняван тест за всеки ред) — виж прогреса там.

## Продуктови решения (от независимия одит) — ЗАТВОРЕНИ 2026-09-14

- [x] **Email Rules catalog за fresh store** — legacy винаги показва целия
      `ToolRegistry::triggerCatalog()`, а Laravel извлича tool-овете от вече
      съществуващи `run_logs` и добавя само `run_audit`. Така scan правило не
      може да се настрои преди първото изпълнение. Нужно е едно canonical
      Laravel tool catalog с default `off` правило за всеки entry; същият
      catalog може да захрани и непълната Audit навигация.

- [x] **Saved Reports / Ignored Orders / Job Queue губят operational context** —
      Saved Reports няма history chart, recurrence badges, investigation
      actions, ignore и same-day re-audit; Ignored Orders няма `seen in
      reports`; Job Queue не показва sanitized payload/result/error summary за
      завършен audit. Laravel подобрява scope/pagination/retry/Horizon, но е
      нужно да се избере кой от липсващия контекст реално трябва за cutover.

- [x] **Приемане на по-строгите Note Flags / Duplicate Addresses / Spot-check
      semantics** — Laravel deduplicate-ва и Unicode-normalize-ва note
      keywords, използва full-country fallback срещу cross-country address
      false positives, и canonicalize/validate/deduplicate-ва Spot-check
      входа. Това са тествани correctness подобрения; не изискват код, а
      изрично приемане като отклонения преди cutover.

- [x] **Dashboard е по-тесен от legacy оперативния overview** — Laravel пази
      основните audit/push/ignored числа и action queue, но няма audit cadence,
      average resolution time, stale ignored, oldest missing, missing-by-type,
      7-day audit история и cache freshness/flush. Да се изберат реално
      използваните сигнали за портване или по-малкият dashboard да се приеме
      изрично преди legacy cutover.

- [x] **Trends е само timeline, без legacy aggregate/repeat-offender анализа** —
      Laravel показва date-filtered missing counts и delta, но не изчислява
      average/worst/clear reports, unique missing или top repeat offenders.
      Нужно е решение дали тези анализи да се върнат, или опростеният timeline
      е достатъчен.

- [x] **Settings няма Sidebar History controls** — legacy пази два toggle-а за
      Missing Orders и Recent Activity sidebar секциите; Laravel няма нито
      секциите, нито настройките им. Това е консистентно премахване, но трябва
      да бъде прието изрично, ако тези бързи sidebar справки вече не трябват.

- [x] **Print Queue canonicalize-ва водещ `#`** — legacy пази въведения
      `#ORD-002`, Laravel го записва като `ORD-002`. Това прави lookup-а и
      deduplication-а по-предвидими и не е върнато назад; нужно е само изрично
      приемане като намерено отклонение преди cutover.

- [x] **Audit/Search discovery навигацията е непълна** — legacy Audit hub
      показва 46 групирани инструмента, а Laravel audit sidebar показва 12;
      route-овете съществуват, но много отчети нямат видим вход. Search пази
      8 от 10 legacy entries и добавя 3 полезни нови, но Customer LTV и Tag
      Audit са преместени към audit route-ове без да присъстват и в audit
      списъка. Нужно е пълен grouped hub/sidebar или изрично решение кои
      инструменти могат да останат достъпни само по URL.

- [x] **Action Log изпуска нормалните operator mutations** — Laravel
      `administration` log покрива основно User/Store промени и няколко admin
      събития, но не записва ignore/unignore/import, push, print queue,
      queue audit, note save, store switch и cache flush. Да се определи кои
      от тези действия изискват audit trail и да се логнат в общите им write
      paths преди махането на legacy.

- [x] **Push Log и Run History нямат филтър** — данните, newest-first редът,
      cap-ът на run history и store scope са запазени/подобрени, но legacy
      позволява моментно търсене по order/tool/status/date/error. Добавяне на
      един server-side `q` филтър е достатъчно, ако операторите го ползват;
      иначе pagination-only поведението трябва да се приеме изрично.

- [x] **Bulk-ignore от чекбокси на report изгледи** — legacy `bulk_ignore_orders`
      (`src/Actions.php::bulkIgnore()`) позволява да маркираш няколко избрани
      order numbers от чекбокси на 6 различни изгледа (`missing-table.php`
      partial, ползван от `run.php`, `refunds.php`, `emailcheck.php`,
      `addrcheck.php`, `trends.php`, плюс собствената форма на `ignored.php`)
      и да ги игнорираш наведнъж с една обща причина. Laravel
      `IgnoredOrderController` има само single-ignore (`store`), CSV import
      (`import`) и bulk *un*ignore по ID (`bulkDestroy`) — **няма bulk-ignore
      по списък от order numbers изобщо**. `laravel-platform-audit.md`
      погрешно твърдеше "bulk" за този ред; коригирано на `Partial`. Нужно е
      продуктово решение: да се построи ли тази bulk-select форма в Laravel
      report изгледите, или да се приеме съзнателно отклонение (single
      ignore + CSV import покриват повечето случаи). Виж
      [`parity-verification.md`](parity-verification.md).

- [x] **Run Audit inline duplicates panel** — legacy `Comparator::findDuplicates()`
      (24-часово clustering, показва се на `views/run.php:87-103` като "N
      potential duplicates detected" при всяко пускане на audit) **няма
      Laravel порт изобщо** — `run-audit.blade.php` няма съответна секция.
      `laravel-platform-audit.md`/`laravel-rewrite.md` погрешно твърдяха, че
      `DuplicateOrderAnalyzer` е портът; той всъщност е порт на отделния
      `dupes` инструмент. Нужно е продуктово решение: да се построи ли тази
      inline секция в Laravel, или да се приеме съзнателно отклонение (audit
      резултатите вече показват missing/found/skipped/ignored без нея). Виж
      [`parity-verification.md`](parity-verification.md).

- [x] **Slack/Discord audit & scan notification content е орязано до едно
      изречение** — legacy `SlackNotifier::auditPayload()`/`scanPayload()` и
      `DiscordNotifier::auditPayload()`/`scanPayload()` пращат структурирано
      съобщение: полета за store/period/missing/matched/skipped/ignored/
      ShipStation total/duration, списък до 10 missing order имена+суми, и
      цветово кодиране (зелено/червено). Laravel `AuditSlackNotification`/
      `ScanSlackNotification`/`AuditDiscordNotification`/`ScanDiscordNotification`
      връщат само едно голо изречение ("{store}: Run Audit found {N} missing
      orders ({period})."), без нито едно от горните полета — данните вече се
      изчисляват в `RunAudit::handle()`/`RecordRun::handle()`, просто не се
      подават на notification конструкторите. `DiscordWebhookChannel` праща
      каквото върне `toDiscord()` verbatim, така че добавяне на `embeds` ключ
      ще проработи директно като при legacy — не е transport ограничение.
      Нужно е продуктово решение: да се разшири ли съдържанието да съвпада с
      legacy, или да се приеме съзнателно опростяване. Виж
      [`parity-verification.md`](parity-verification.md).

- [x] **Email rules нямат global fallback recipient** — legacy build-ва всеки
      `EmailNotifier` от `ALERT_EMAIL` веднъж и всеки tool-ов `recipientFor()`
      само override-ва тази стойност; празен per-tool email нарочно означава
      "прати на ALERT_EMAIL", не "не пращай". Затова legacy оператор може да
      включи "immediate" на всичките ~40 tool-а в trigger catalog-а и да не
      въвежда адрес никъде. Laravel няма никакъв еквивалент на ALERT_EMAIL —
      `EmailRulesRequest` изисква изричен email за всеки tool с mode различен
      от `off`, а `RecordRun::handle()` просто не пуска известие, ако е
      празен. Не е живо счупване (формата не позволява да се запази празен
      immediate rule), но е реална изгубена удобство: всеки от 40-те tool-а
      трябва отделно да получи същия адрес вместо един env var да ги покрива
      всичките. Нужно е продуктово решение: да се добави ли global default
      recipient концепция, или да се приеме изричното per-tool изискване
      като по-ясен design избор. Виж [`parity-verification.md`](parity-verification.md).

- [x] **Fraud risk signals нямат per-signal точки** — legacy `RiskScorer::score()`
      връща `signals` като `list<{label, points}>`, и `ViewHelpers::riskBadge()`
      показва всеки сигнал с приноса му към резултата (напр. "Fraud/high-risk
      tag +35") в expandable breakdown. Laravel `OrderRiskScorer::score()`
      връща само `list<string>` (голи label-и, без points) — консумирано
      директно от 3 blade изгледа (`fraud-risk`, `spot-check`,
      `orders/timeline`). Резултатът (`score`/`level`) е коректен и на двете
      страни (потвърдено с диференциален тест), само breakdown-ът липсва —
      оператор вижда *че* поръчка е рискова, но не и *кой конкретен сигнал
      колко тежи*. Нужно е продуктово решение: да се разшири ли `signals`
      формата (един domain клас + 3 blade изгледа + 2 съществуващи unit
      теста) до structured points, или да се приеме съзнателно опростената
      breakdown-по-нищо форма. Виж [`parity-verification.md`](parity-verification.md).

- [x] **High-Value No Phone има currency филтър, който legacy никога не е
      имал** — `HighValueNoPhoneAnalyzer::analyze()` приема `$currency`
      параметър и тихо изключва всяка поръчка, чиято `currency` не съвпада
      точно (form поле, подразбиране `USD`, `HighValueNoPhoneRequest`
      валидация). Legacy `buildHvOrderRows()` флагва high-value поръчки без
      телефон независимо от валутата. За store само в USD подразбирането
      възпроизвежда legacy точно; за multi-currency store (Shopify Markets)
      или ако operator смени полето, отчетът тихо пропуска high-value
      поръчки в друга валута — точно обратното на целта на отчета (хване
      скъпа поръчка, която може да не се достави). Нужно е продуктово
      решение: да се запази ли currency филтъра (и как да изглежда "всички
      валути" — опция "any", или per-currency scan), или да се премахне за
      съответствие с legacy. Виж [`parity-verification.md`](parity-verification.md).

## Production/infra решения

- [x] **Security headers/cookies/proxy code** — CSP/frame/referrer/HSTS policy,
      secure cookie settings, explicit trusted proxies, config validation и tests.
- [ ] **SMTP transport production setup** — реални secrets в deploy
      конфигурацията и реален staging delivery smoke test.
- [x] **Readiness endpoint (`/ready`) разширение** — database, cache,
      queue configuration и worker-heartbeat freshness проверки.
- [x] **Structured application logs contract** — request/run/store/tool context,
      status/category полета, recursive redaction и URL query stripping.
- [x] **Operational alerts разширение** — queue latency >5 min,
      scheduler heartbeat >5 min и 3 API/report failures за 15 min, с 15-min
      deduplication и Slack/Discord delivery.
- [x] **Audit jobs progress/terminal-state UI** — store-scoped queued/running/
      completed/failed history в Job Queue екрана.
- [x] **Cache policy** — production Redis с unique deployment prefix, само за
      locks, unique jobs и health heartbeats; external/report reads остават fresh.
- [x] **Configuration validation — trusted proxy** — production checks за
      trusted proxies, secure session cookie и HSTS.
- [ ] **Backup and restore rehearsal изпълнение** — runbook-ът вече
      описва destination ownership, retention, safe restore и evidence; остава
      реалната production-like репетиция.

## Процес (не код)

- [ ] **Production TLS/proxy verification** — потвърждаване на real client
      IP/scheme, secure cookie и HSTS зад реалния load balancer/reverse proxy.

- [ ] **UAT и cutover rehearsal изпълнение** — чеклистът е готов
      ([`docs/laravel-uat-cutover-checklist.md`](laravel-uat-cutover-checklist.md)),
      но двете реални production-like репетиции не са насрочени/изпълнени.
