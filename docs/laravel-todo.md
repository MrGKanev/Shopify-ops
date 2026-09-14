# Laravel rewrite — отворени задачи

Последно обновяване: **2026-09-14**.

Списък със самостоятелни отворени product-decision задачи, всяка от които не
изисква преработка на съседен код. По-старите self-reported "72/72 Done"
одитни документи (`laravel-platform-audit.md`, `laravel-rewrite.md`) бяха
премахнати на 2026-09-14 — техните твърдения не бяха доказани изпълнимо и в
няколко реда бяха грешни (виж по-долу). Единственото място, което вече
твърди feature parity, е [`docs/parity-verification.md`](parity-verification.md)
(изисква линкнат, реално изпълняван тест за всеки ред) — виж прогреса там.

## Продуктови решения (от независимия одит)

- [ ] **Bulk-ignore от чекбокси на report изгледи** — legacy `bulk_ignore_orders`
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

- [ ] **Run Audit inline duplicates panel** — legacy `Comparator::findDuplicates()`
      (24-часово clustering, показва се на `views/run.php:87-103` като "N
      potential duplicates detected" при всяко пускане на audit) **няма
      Laravel порт изобщо** — `run-audit.blade.php` няма съответна секция.
      `laravel-platform-audit.md`/`laravel-rewrite.md` погрешно твърдяха, че
      `DuplicateOrderAnalyzer` е портът; той всъщност е порт на отделния
      `dupes` инструмент. Нужно е продуктово решение: да се построи ли тази
      inline секция в Laravel, или да се приеме съзнателно отклонение (audit
      резултатите вече показват missing/found/skipped/ignored без нея). Виж
      [`parity-verification.md`](parity-verification.md).

- [ ] **Slack/Discord audit & scan notification content е орязано до едно
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

- [ ] **Email rules нямат global fallback recipient** — legacy build-ва всеки
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

- [ ] **Fraud risk signals нямат per-signal точки** — legacy `RiskScorer::score()`
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
