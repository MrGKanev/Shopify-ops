<?php

declare(strict_types=1);

use App\Models\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlackRulesParityTest extends TestCase
{
    /**
     * Legacy `SlackRules::normalise()` (`src/SlackRules.php`) is applied both on
     * save AND on every load, so stale/hand-edited data in `slack_rules.json` is
     * always re-sanitised before use. Laravel's `Store::resolvedSlackRules()`
     * (`laravel/app/Models/Store.php`) is the equivalent "resolve stored rules
     * for use" call read directly by `RunAudit::handle()` before building the
     * `AuditSlackNotification` mentions prefix — same responsibility, same call
     * site shape, both driven from a raw stored-rules array.
     *
     * @param array<string, mixed> $raw
     * @dataProvider rawRulesProvider
     */
    #[DataProvider('rawRulesProvider')]
    public function test_resolved_rules_match_legacy(array $raw): void
    {
        $legacy = \SlackRules::normalise($raw);
        $laravel = (new Store(['slack_rules' => $raw]))->resolvedSlackRules();

        ksort($legacy);
        ksort($laravel);

        $this->assertSame($legacy, $laravel);
    }

    /** @return list<array{0: array<string, mixed>}> */
    public static function rawRulesProvider(): array
    {
        return [
            'defaults from empty input' => [[]],
            'clean typical data' => [[
                'audit_enabled' => true, 'audit_min_missing' => 2, 'scan_enabled' => true,
                'scan_min_rows' => 5, 'include_zero_audit' => false, 'mentions' => 'U0123ABCDE',
            ]],
            'stringly-typed booleans and ints' => [[
                'audit_enabled' => '1', 'audit_min_missing' => '3', 'scan_enabled' => '0',
                'scan_min_rows' => '0', 'include_zero_audit' => '', 'mentions' => '',
            ]],
            'negative min values get clamped' => [[
                'audit_min_missing' => -5, 'scan_min_rows' => -1,
            ]],
            'mentions mixed with free text and punctuation' => [[
                'mentions' => 'hello <@U0123ABCDE> world foo@bar.com S0123ABCDE!!',
            ]],
            'mentions lowercase and duplicated' => [[
                'mentions' => 'u0123abcde u0123abcde w0123abcde',
            ]],
        ];
    }
}
