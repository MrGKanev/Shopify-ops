# Laravel rewrite — отворени задачи

Последно обновяване: **2026-09-13**.

Извлечено от [platform audit-а](laravel-platform-audit.md) — всеки ред там
е `Done` освен изброените тук. Всяка задача е самостоятелна, не изисква
преработка на съседен код.

Текущо състояние (self-reported, виж бележката по-долу): feature parity
**72/72**, legacy test audit **115/115**. Този файл е единственият кратък
списък за оставащата работа; подробните доказателства остават в audit
документите.

**Независима проверка в процес:** горните числа не са били доказани
изпълнимо преди 2026-09-13 — вижте [`parity-verification.md`](parity-verification.md)
за прогреса на независимия differential-test одит (1/72 инструмента
потвърден до момента, 1 регресия намерена и оправена, 1 нов пропуск
намерен — виж по-долу).

## Продуктови решения (от независимия одит)

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
