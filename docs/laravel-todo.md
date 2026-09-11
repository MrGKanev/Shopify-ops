# Laravel rewrite — отворени задачи

Последно обновяване: **2026-09-11**.

Извлечено от [platform audit-а](laravel-platform-audit.md) — всеки ред там
е `Done` освен изброените тук. Всяка задача е самостоятелна, не изисква
преработка на съседен код.

## Production/infra решения

- [ ] **Security headers/cookies/proxy** — CSP/frame/referrer/HSTS policy,
      secure cookie settings и trusted proxies, проверени зад production
      TLS proxy.
- [ ] **SMTP transport production setup** — реални secrets в deploy
      конфигурацията и реален staging delivery smoke test.
- [ ] **Readiness endpoint (`/ready`) разширение** — worker freshness и
      cache readiness проверки, след като worker/production cache
      foundation-ите по-долу се изберат.
- [ ] **Structured application logs contract** — единен request/run/store/tool
      ID context, error category/status полета, redaction tests и избор на
      production log channel/retention.
- [ ] **Operational alerts разширение** — queue latency, повтарящи се API
      failures и scheduler-absence тригери (в момента покрива само failed
      jobs + notification failures — съзнателно ограничен обхват).
- [ ] **Queue storage production избор** — production queue connection,
      worker config и health visibility.
- [ ] **Audit jobs progress/terminal-state UI** — отделно от run history
      екрана (timeouts/backoff вече са зададени за `RunAuditJob`).
- [ ] **Cache policy** — key namespacing по store/query, TTL matrix, locks,
      invalidation и corruption/failure strategy + tests.
- [ ] **Configuration validation — trusted proxy** — `CheckConfiguration`
      вече покрива app/store/order-types/tag-policy/mail/notifications;
      остава само trusted proxy настройка (виж Security headers/cookies/proxy
      реда по-долу — DB connectivity вече е в `/ready`).
- [ ] **Backup and restore rehearsal** — restore repetition, retention policy
      документация и избор на storage-destination owner (download вече е
      лесен през `Admin\BackupController`).

## Процес (не код)

- [ ] **UAT и cutover rehearsal изпълнение** — чеклистът е готов
      ([`docs/laravel-uat-cutover-checklist.md`](laravel-uat-cutover-checklist.md)),
      но двете реални production-like репетиции не са насрочени/изпълнени.

## Doc cleanup

- [ ] **`docs/laravel-rewrite.md` reconciliation** — горният summary чеклист
      (около ред 448) все още показва невярно `[ ]` за "Background jobs,
      idempotency..." и "Final parity review, UAT..." въпреки завършената
      работа тази сесия; по-долу редът "Report persistence, downloads и
      CSV/export contracts" също е грешно `[ ]`, докато platform audit-ът
      казва `Done` и за двете. Трябва да се сверят двата документа.
