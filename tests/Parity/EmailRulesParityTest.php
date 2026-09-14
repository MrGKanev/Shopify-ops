<?php

declare(strict_types=1);

use App\Models\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailRulesParityTest extends TestCase
{
    /**
     * Legacy `EmailRules::normalise()` (`src/EmailRules.php`) re-validates the
     * per-tool recipient email address on every `load()`, not just `save()` —
     * same defense-in-depth reasoning as `SlackRules::normalise()`'s mentions
     * regex (see `SlackRulesParityTest`). `Store::resolvedEmailRules()`
     * (`laravel/app/Models/Store.php`) is the read-time equivalent, called
     * directly by `RecordRun::handle()` before routing an immediate-mode
     * notification to `$emailRule['email']`.
     *
     * Only entries for tools present in the raw input, with a *valid* mode,
     * are compared here. Two confirmed shape differences are scoped out
     * (documented in parity-verification.md, not asserted here) because
     * neither changes observable behaviour at any current call site — every
     * caller reads a single tool via `[$tool] ?? null` and treats a missing
     * entry exactly like an `off` one:
     *  - legacy's `normalise()` fills in every `ToolRegistry::triggerCatalog()`
     *    tool missing from the input (defaulted to `mode: off`);
     *    `resolvedEmailRules()` only returns tools actually present in
     *    `email_rules`.
     *  - legacy clamps an invalid `mode` to `off` but keeps the entry (with
     *    its other fields normalised); `resolvedEmailRules()` drops the
     *    whole entry when `mode` isn't one of `off`/`immediate`/`digest`.
     * Both only matter for data that never passed through `EmailRulesRequest`
     * (which already rejects invalid modes) — same caveat as the mentions
     * gap fixed in `SlackRulesParityTest`.
     *
     * @param array<string, mixed> $raw
     * @param list<string> $tools
     */
    #[DataProvider('rawRulesProvider')]
    public function test_resolved_rule_matches_legacy_per_tool(array $raw, array $tools): void
    {
        $legacy = \EmailRules::normalise($raw);
        $laravel = (new Store(['email_rules' => $raw]))->resolvedEmailRules();

        foreach ($tools as $tool) {
            $this->assertSame($legacy[$tool], $laravel[$tool], "tool: {$tool}");
        }
    }

    /** @return list<array{0: array<string, mixed>, 1: list<string>}> */
    public static function rawRulesProvider(): array
    {
        return [
            'clean immediate rule' => [
                ['run_audit' => ['mode' => 'immediate', 'threshold' => 2, 'include_zero' => true, 'email' => 'ops@example.com']],
                ['run_audit'],
            ],
            'stringly-typed threshold and bool' => [
                ['find_dupes' => ['mode' => 'digest', 'threshold' => '0', 'include_zero' => '1', 'email' => '']],
                ['find_dupes'],
            ],
            'run_audit threshold floors at 0, others floor at 1' => [
                [
                    'run_audit' => ['mode' => 'immediate', 'threshold' => -5, 'include_zero' => false, 'email' => 'a@b.com'],
                    'find_dupes' => ['mode' => 'immediate', 'threshold' => -5, 'include_zero' => false, 'email' => 'a@b.com'],
                ],
                ['run_audit', 'find_dupes'],
            ],
            'invalid email is silently cleared, not rejected' => [
                ['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true, 'email' => 'not-an-email']],
                ['run_audit'],
            ],
            'email with stray whitespace' => [
                ['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true, 'email' => '  ops@example.com  ']],
                ['run_audit'],
            ],
        ];
    }
}
