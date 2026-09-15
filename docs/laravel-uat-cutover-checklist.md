# Laravel rewrite — UAT и cutover rehearsal checklist

Последно обновяване: **2026-09-11**.

Този документ е чеклист/процес, не код. Той подготвя двете production-like
repetitions ("Hardening и release candidate" и "Cutover" фазите), gate-нати
от пълна `Verified match` завършеност в [`docs/parity-verification.md`](parity-verification.md)
(единственото място, което твърди feature parity — виж дизайна в
[`superpowers/specs/2026-09-13-parity-verification-design.md`](superpowers/specs/2026-09-13-parity-verification-design.md)).
Реалната дата на репетицията все още не е насрочена — виж "Отворени въпроси"
накрая.

## 1. Golden fixtures

Целта е повторяем, сравним dataset — не production данни на живо и не
случайни тестови поръчки.

- **Източник**: анонимизиран export от реален Shopify/ShipStation store с
  представителен обем — достатъчно поръчки да покрият всеки branch в
  `order_types.json` (Z1/Z2 orders изискват Accent Piece, Funnel Cap, Burr Set
  — виж `required_items` в конфигурацията), включително Z1/Z2 required-items
  случаи, mismatched/missing items,
  address edits, duplicate orders и refunds.
- **Формат**: заснет като fixture JSON/CSV под контрол на repo-то (или
  отделен private fixtures repo, ако данните са твърде чувствителни за
  публичен history — решение за собственика на данните, не техническо).
  PII (имена, адреси, email) се маскира преди commit, докато структурата
  остава вярна за Shopify/ShipStation payload схемите.
- **Обхват**: минимум по един fixture ред за всеки от 72-та tools/reports в
  `src/ToolRegistry.php` (проследени поинструментно в
  [`docs/parity-verification.md`](parity-verification.md)), плюс edge cases
  от production bug fixes-ите, документирани в тази сесия
  (address-edit double counting, missing event classifiers, EmailDigest
  24h→calendar day и др. — виж git history на branch-а).
- **Сравнение**: fixture run-ът минава през legacy PHP инструмента и през
  Laravel еквивалента; резултатите (counts, CSV rows, notification payloads)
  се diff-ват ред по ред. Разлика без изрично прието отклонение блокира
  release-а.
- **Refresh policy**: fixture-ите се обновяват само когато legacy source
  данните вече не покриват нов edge case — не при всеки release.

## 2. Rehearsal environment

- Production-like среда: същата OS/PHP/DB версия като production VPS,
  различен hostname/DNS, изолирани credentials (не истинските Shopify/
  ShipStation/Slack/Discord/SMTP секрети — виж `.env` live-credentials
  предпазната мярка от паметта на проекта).
- Fresh install по [deployment runbook-а](laravel-deployment-runbook.md)
  "Първоначална инсталация" стъпка по стъпка, без ръчни shortcuts.
- Worker и scheduler вдигнати (supervisor + cron), не само `php artisan serve`.

## 3. Изпълнение на репетицията (х2, независимо една от друга)

1. Fresh install в rehearsal средата (виж runbook-а).
2. Зареждане на golden fixtures данните.
3. Изпълнение на пълния feature-parity matrix workflow ръчно или през
   automated UAT script — login, store context, всеки report/audit,
   push-to-ShipStation, order note update, export, notification delivery
   (email/Slack/Discord), queued audit job.
4. Diff на резултатите срещу legacy baseline-а (виж golden fixtures секцията).
5. Cutover dry-run: write freeze → спиране на legacy cron/workers → deploy
   → smoke checks → observe период — без реално изключване на production
   legacy системата.
6. Документиране на всеки дефект/разлика с severity и owner.
7. Втората репетиция се изпълнява само след като всички severity-blocking
   находки от първата са затворени.

## 4. Sign-off evidence

За всяка репетиция се пази (в PR/issue/доклад, не само в паметта на екипа):

- [ ] Дата, среда, git commit/tag на Laravel кода.
- [ ] Fixture dataset версия/hash, използван за диф-а.
- [ ] Пълен резултат от feature-parity diff-а (нула недокументирани разлики).
- [ ] CI резултат (PHPUnit, Larastan, Pint, Composer audit, frontend build)
      на release commit-а.
- [ ] Security review resolution.
- [ ] Продуктово одобрение (owner sign-off) отделно от техническото.

## 5. Irreversible cutover checklist

Само след успешна втора репетиция и пълен sign-off. Cutover-ът е one-way —
не се планира rollback или паралелна работа на двете системи след него.

- [ ] И двете rehearsals са Clean (без открити blocking находки).
- [ ] Всеки ред в [`docs/parity-verification.md`](parity-verification.md) е
      `Verified match` или има изрично прието отклонение.
- [ ] Write freeze обявен на засегнатите потребители.
- [ ] Legacy cron/workers спрени, in-flight jobs изчакани или съзнателно
      прекратени.
- [ ] Production administrator/stores/secrets конфигурирани в чистата
      Laravel инсталация — изрично няма import на legacy users/jobs/logs/
      cache/reports/settings.
- [ ] Production observability активиран доколкото е решено (Sentry DSN,
      Horizon/queue избор, `/metrics` scrape).
- [ ] Smoke checks минати по deployment runbook-а.
- [ ] Legacy приложението изключено — не остава като fallback.
- [ ] Post-cutover monitoring период дефиниран (продължителност, кой следи
      errors/queue latency/failed jobs/audit deviations).

## Отворени въпроси

- **Дата на реалната репетиция**: не е насрочена. Този документ подготвя
  процеса предварително; датата и собственика на golden fixtures export-а
  трябва да се потвърдят отделно.
- **Production observability активиране** (Sentry DSN, Horizon queue избор,
  `/metrics` scrape stack) е съзнателно отложено.
