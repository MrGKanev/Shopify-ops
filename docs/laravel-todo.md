# Laravel rewrite — отворени задачи

Последно обновяване: **2026-09-13**.

Извлечено от [platform audit-а](laravel-platform-audit.md) — всеки ред там
е `Done` освен изброените тук. Всяка задача е самостоятелна, не изисква
преработка на съседен код.

Текущо състояние: feature parity **72/72**, legacy test audit **115/115**.
Този файл е единственият кратък списък за оставащата работа;
подробните доказателства остават в audit документите.

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
