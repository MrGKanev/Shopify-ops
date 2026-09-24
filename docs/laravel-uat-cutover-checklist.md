# Production UAT и release sign-off

Последно обновяване: **2026-09-24**. Статус: checklist за проверка; отметките
по-долу не удостоверяват вече проведена репетиция.

Repository cutover-ът към root Laravel приложение е приключил: няма активни
`laravel/`, `src/ToolRegistry.php` или root `order_types.json`. Това не е
доказателство за production cutover или успешно production UAT. Старият
Laravel/legacy parity matrix е [исторически запис](migration-history.md), а
текущият release gate е [CI workflow-ът](../.github/workflows/ci.yml) за
конкретния release commit.

## Подготовка на средата и данните

- [ ] Определи release commit, owner, rehearsal дата, целева среда и критерии
  за блокиращ дефект.
- [ ] Използвай production-like PHP, database, Redis, web server, Horizon и
  scheduler конфигурация; отдели hostname, database и външните credentials от
  production.
- [ ] Подготви анонимизиран, версиониран dataset с обичайните и граничните
  случаи за реално използваните audit/report и operator flows. Запиши версия
  или hash и ограниченията на покритието.
- [ ] Изпълни инсталацията или deploy стъпките от
  [runbook-а](laravel-deployment-runbook.md) на точния release commit.

## Проверки във всяка репетиция

- [ ] Запази успешния CI run за същия commit: Composer audit, тестове,
  static analysis, Pint, pnpm install/build и pnpm audit.
- [ ] Провери login, роли, store isolation, основните audit/report flows,
  saved reports, CSV export, ignore/unignore, order actions и queued audit.
- [ ] Провери SMTP с реалистични staging credentials: test email, email rule
  с изричен получател, fallback към default alert email, получено съобщение
  и видимо поведение при грешка. Провери Slack/Discord само ако са включени
  за deployment-а.
- [ ] Изпълни backup и restore в изолирана база/host по runbook-а. Запиши
  archive ID, коректния `APP_KEY`, успешно decrypt-ване на store credentials,
  файлове/права и boot резултат. Archive verification сама по себе си не е
  restore rehearsal.
- [ ] През реалния TLS/proxy път провери HTTPS URL/scheme, клиентски IP,
  secure session cookie, HSTS и `TRUSTED_PROXIES` само когато има proxy.
- [ ] Провери `/up` и `/ready`, реално изпълнен scheduler job, работещ
  Horizon worker, queue failure/latency alerts и избрания observability
  backend/получатели.
- [ ] Запиши дефектите с severity, owner и резултат от повторната проверка.

## Две production-like репетиции

- [ ] Репетиция 1: fresh deploy, dataset, основни flows, интеграции,
  backup/restore, TLS/proxy, monitoring и документиран резултат.
- [ ] Затвори blocking дефектите от първата репетиция; ако release commit-ът
  се промени, повтори засегнатите проверки на новия commit.
- [ ] Репетиция 2: повтори end-to-end flow върху release candidate и запиши
  отделен резултат. Ако репетициите вече са проведени, приложи съществуващите
  доказателства вместо да ги отбелязваш без запис.

## Sign-off запис

За всяка репетиция и за production release запази на едно място:

| Поле | Стойност |
| --- | --- |
| Дата, среда, owner | Да се попълни |
| Release commit и CI run | Да се попълни |
| Dataset версия/hash | Да се попълни |
| SMTP и външни интеграции | Да се попълни |
| Backup archive и restore резултат | Да се попълни |
| TLS/proxy и health/worker/scheduler резултат | Да се попълни |
| Blocking дефекти и повторна проверка | Да се попълни |
| Техническо и продуктово одобрение | Да се попълни |

Production sign-off е готов, когато двете репетиции имат доказателства,
blocking дефектите са затворени и release owner-ът е приел резултата.
