# Активен backlog след Laravel миграцията

Последно прегледано: **2026-09-24**. Repository cutover-ът към Laravel в root
е приключил. Това не удостоверява production sign-off. Историческите parity
решения и старите планове са достъпни през [архивния индекс](migration-history.md).
„Реализирано“ по-долу означава потвърден код и намерени тестове, а не успешно
изпълнение на текущия test suite или проверка в production.

## P1 — доказателства преди production sign-off

Следи подробните стъпки и запиши резултатите в
[UAT checklist-а](laravel-uat-cutover-checklist.md):

- [ ] Потвърди успешния [CI release gate](laravel-deployment-runbook.md#release-gate)
  за точния deploy commit и запази run ID/резултатите.
- [ ] Провери SMTP доставка в staging: изричен rule recipient, fallback към
  store default alert email, получено съобщение и поведение при грешка.
- [ ] Проведи backup и restore репетиция в изолирана среда: database, private
  files, правилния `APP_KEY`, decrypt на store credentials, права и boot.
- [ ] Провери HTTPS през реалния deployment път: scheme, client IP, secure
  cookies, HSTS и trusted proxies, когато има reverse proxy.
- [ ] Потвърди работещи Horizon worker, scheduler, alerts и избрания
  observability backend/получатели в целевата среда.
- [ ] Документирай две production-like UAT репетиции или приложи съществуващи
  доказателства: дата, среда, commit, dataset, дефекти, owner и sign-off.

## P2 — продуктови уточнения

Тези точки изискват изрично продуктово решение преди промяна на поведението:

- [ ] **Dashboard chart:** текущият controller показва последните седем
  audit snapshot записа (`$recent->slice(-7)`), а UI ги обозначава като
  „Last N audits“. Потвърди дали това покрива продуктовата нужда, или е
  необходима агрегация по седем календарни дни, включително дни без отчет.
- [ ] **Bulk-ignore извън Run Audit:** избор на няколко поръчки с обща причина
  е реализиран в Run Audit. Реши дали е нужен и в други report изгледи;
  старият legacy списък сам по себе си не е текущо изискване.
- [ ] **Date range в log search:** Push Log и Run History имат server-side
  `q` търсене с запазване при pagination. Реши дали е нужен отделен date-range
  filter; текущото `q` не го предоставя.

## P2 — интерфейс

- [ ] Изпълни [остатъчния UI план](frontend-design-consistency-plan.md):
  browser проверка на light/dark, mobile, forms, tables и empty/error states,
  следвана от измерен CSS usage audit преди cleanup.

## Приключени решения — кратък запис

- **Operator flows:** Action Log обхваща operator mutations; Job Queue показва
  резултат/категория грешка; Saved Reports има history, recurrence и quick
  actions; Ignored Orders показва recurrence. Търсенето `q` е реализирано за
  Push Log и Run History.
- **Audit и reports:** Dashboard има cadence, resolution, stale ignored,
  oldest missing, type breakdown, chart и cache flush; Trends има агрегати и
  repeat offenders. Run Audit има отделен 24-часов inline duplicates panel и
  bulk-ignore по избор. High-Value No Phone има `ALL` currency option.
- **Навигация и известия:** Audit/Search hubs са групирани; Email Rules ползва
  tool catalog и store default alert email; Slack/Discord audit и scan
  известията имат структурирано съдържание. Fraud risk signals включват
  точки за всеки сигнал.
- **Приети отклонения:** по-строгите Note Flags, Duplicate Addresses и
  Spot-check правила, премахнатите Sidebar History controls и нормализирането
  на водещ `#` в Print Queue остават взети продуктови решения. Възстановяването
  на legacy поведение би било нова задача.
- **Operational код:** security headers/cookies, optional trusted proxies,
  readiness checks, structured logs, alerts, Redis/Horizon, backup verification
  и `backup:restore` са реализирани. Реалната работа и доставка в deployment
  средата се проверяват в P1 по-горе.
- **Продуктова документация:** [audit-checks.md](audit-checks.md) изброява
  инструментите с CSV download; [search-lookup.md](search-lookup.md) описва
  fresh API reads и локалните данни в Global Search.

Подробните исторически сравнения, приети отклонения и тогавашни бройки тестове
са в [архивните документи](migration-history.md). Те не са текущ CI или UAT
резултат.
