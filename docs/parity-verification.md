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
| `dupes` (Duplicate Detector) | `src/Shopify/GraphQL/DuplicateOrderInsights.php::findDuplicateOrders()` | `laravel/app/Domain/Reports/DuplicateOrderAnalyzer.php::analyze()` | `tests/Parity/DuplicateDetectorParityTest.php` | Verified mismatch (fixed) | Laravel used an 86400s (24-hour) window instead of the real tool's 600s (10-minute) window — over-flagged pairs 10 minutes to 24 hours apart as duplicates. Fixed 2026-09-13; `laravel/tests/Unit/Domain/Reports/DuplicateOrderAnalyzerTest.php` updated to match. |
