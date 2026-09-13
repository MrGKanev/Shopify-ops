# Независима проверка на Laravel/legacy parity

Този документ е единственото място, което твърди parity между legacy и
Laravel системата. Ред получава verdict, различен от `Not yet audited`,
само след линкнат differential тест, който реално се изпълнява
(`vendor/bin/phpunit -c phpunit.parity.xml`).

Дизайн: [`docs/superpowers/specs/2026-09-13-parity-verification-design.md`](superpowers/specs/2026-09-13-parity-verification-design.md).

Verdict е едно от: `Verified match` / `Verified mismatch (fixed)` /
`Verified mismatch (open)` / `Not yet audited`.

| Tool | Legacy reference | Laravel reference | Differential test | Verdict | Notes |
|---|---|---|---|---|---|
