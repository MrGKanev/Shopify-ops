# История на Laravel миграцията

Последно обновяване: **2026-09-24**. Този индекс сочи архивните документи от
legacy PHP → Laravel миграцията. Те описват решения и проверки към тогавашната
структура на repo-то; не са текущ deployment runbook, активен backlog или
доказателство за production sign-off.

Последният revision с всички изброени файлове е
`383660ab562652d55246f4eeba2a0aa5a981c54f` (родителят на commit
`87932c7`, който ги премахва). Преглед на конкретен файл:

```bash
git show 383660ab562652d55246f4eeba2a0aa5a981c54f:docs/parity-verification.md
```

| Архивен документ | Историческа стойност | Важна граница |
| --- | --- | --- |
| `docs/parity-verification.md` | Регистър на differential находки и приети отклонения | Описва стария dual-codebase harness. `tests/Parity/`, `phpunit.parity.xml` и parity CI job липсват в текущия root repo. Старите verdict-и и бройки не са актуален release gate. |
| `docs/laravel-test-audit.md` | Method-level mapping на старите тестове към Laravel | Броят 115/115 и историческите PHPUnit totals не измерват текущото coverage или текущо успешно изпълнение. |
| `docs/superpowers/plans/2026-09-13-parity-verification-pilot.md` | Pilot план и мотиви за differential тестовете | Командите и dual-autoload структурата са за премахнатия legacy layout. |
| `docs/superpowers/plans/2026-09-14-pre-cutover-build-list.md` | Приетите тогава продуктови разлики и implementation последователност | Незачеркнати стари стъпки не са автоматично отворени задачи днес. |
| `docs/superpowers/specs/2026-09-13-parity-verification-design.md` | Дизайн и ограничения на историческия comparison метод | Не задава текущ архитектурен контракт или задължение за възстановяване на harness-а. |
| `docs/laravel-uat-cutover-checklist.md` в същия revision | Първоначалният legacy cutover план | Заменен е с [текущия UAT checklist](laravel-uat-cutover-checklist.md); repository cutover и production sign-off са различни събития. |

Историческият tag `legacy-final` също е наличен за преглед на стария код.
Текущата проверка на release commit-а е описана в
[deployment runbook-а](laravel-deployment-runbook.md#release-gate) и
[CI workflow-а](../.github/workflows/ci.yml). Записът на отворените
production проверки е в [UAT checklist-а](laravel-uat-cutover-checklist.md).
