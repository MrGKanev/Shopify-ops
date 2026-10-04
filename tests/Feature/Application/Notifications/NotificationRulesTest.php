<?php

namespace Tests\Feature\Application\Notifications;

use App\Application\Notifications\ChatRules;
use App\Application\Notifications\EmailRule;
use App\Application\Notifications\EmailRules;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationRulesTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('chatThresholds')]
    public function test_chat_rules_match_only_enabled_thresholds_and_allowed_zero_counts(array $stored, int $rows, bool $audit, bool $scan): void
    {
        $rules = ChatRules::fromArray($stored);

        $this->assertSame($audit, $rules->audit->matches($rows));
        $this->assertSame($scan, $rules->scan->matches($rows));
    }

    public static function chatThresholds(): array
    {
        return [
            'defaults permit zero audit' => [[], 0, true, false],
            'disabled audit' => [['audit_enabled' => false], 10, false, false],
            'below thresholds' => [['audit_min_missing' => 2, 'scan_enabled' => true, 'scan_min_rows' => 2], 1, false, false],
            'at thresholds' => [['audit_min_missing' => 2, 'scan_enabled' => true, 'scan_min_rows' => 2], 2, true, true],
            'zero excluded' => [['include_zero_audit' => false], 0, false, false],
            'negative count' => [[], -1, false, false],
            'negative thresholds normalized' => [['audit_min_missing' => -2, 'scan_enabled' => true, 'scan_min_rows' => -3], 0, true, false],
            'disabled scan' => [['scan_enabled' => false], 10, true, false],
        ];
    }

    #[DataProvider('emailThresholds')]
    public function test_email_rules_normalize_thresholds_and_match_enabled_modes(string $tool, array $stored, int $rows, bool $matches): void
    {
        $rule = EmailRule::fromArray($tool, $stored);

        $this->assertSame($matches, $rule->matches($rows));
    }

    public static function emailThresholds(): array
    {
        return [
            'off' => ['run_audit', ['mode' => 'off', 'threshold' => 0, 'include_zero' => true], 10, false],
            'below threshold' => ['fraud_risk', ['mode' => 'immediate', 'threshold' => 2], 1, false],
            'at threshold' => ['fraud_risk', ['mode' => 'immediate', 'threshold' => 2], 2, true],
            'digest' => ['fraud_risk', ['mode' => 'digest', 'threshold' => 2], 3, true],
            'audit zero allowed' => ['run_audit', ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true], 0, true],
            'audit zero excluded' => ['run_audit', ['mode' => 'digest', 'threshold' => 0], 0, false],
            'scan has positive minimum' => ['fraud_risk', ['mode' => 'immediate', 'threshold' => -1, 'include_zero' => true], 0, false],
            'invalid mode disabled' => ['run_audit', ['mode' => 'invalid', 'threshold' => 0, 'include_zero' => true], 2, false],
        ];
    }

    public function test_casts_read_legacy_json_and_persist_value_objects_with_normalized_mentions(): void
    {
        $store = Store::factory()->create();
        DB::table('stores')->where('id', $store->id)->update([
            'slack_rules' => json_encode(['scan_enabled' => true, 'scan_min_rows' => 3, 'mentions' => 'jane@example.com u012abc3de U012ABC3DE S024XYZ9FG']),
            'email_rules' => json_encode(['run_audit' => ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => ' ops@example.com ']]),
        ]);
        $store->refresh();

        $this->assertTrue($store->slack_rules->scan->matches(3));
        $this->assertSame('U012ABC3DE S024XYZ9FG', $store->slack_rules->mentions);
        $this->assertSame('ops@example.com', $store->email_rules->forTool('run_audit')->recipient(null));
        $replacement = ChatRules::fromArray(['audit_enabled' => false, 'mentions' => 'W012ABCDE9']);
        $store->update(['slack_rules' => $replacement, 'email_rules' => EmailRules::fromArray(['fraud_risk' => ['mode' => 'immediate', 'threshold' => 2]])]);

        $this->assertSame($replacement->toArray(), $store->fresh()->slack_rules->toArray());
        $this->assertTrue($store->fresh()->email_rules->forTool('fraud_risk')->matches(2));
        $this->assertSame($replacement->toArray(), $store->fresh()->toArray()['slack_rules']);
    }

    public function test_reading_null_defaults_does_not_rewrite_the_stored_rules(): void
    {
        $store = Store::factory()->create(['slack_rules' => null, 'discord_rules' => null, 'email_rules' => null]);

        $this->assertTrue($store->slack_rules->audit->matches(0));
        $this->assertFalse($store->discord_rules->scan->matches(3));
        $this->assertNull($store->email_rules->forTool('missing'));
        $store->update(['label' => 'Updated store']);

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'slack_rules' => null, 'discord_rules' => null, 'email_rules' => null]);
    }

    public function test_invalid_email_rules_are_ignored_and_recipients_fall_back_to_store_defaults(): void
    {
        $rules = EmailRules::fromArray(['broken' => 'bad', 'invalid_mode' => ['mode' => 'invalid'], 'run_audit' => ['mode' => 'digest', 'email' => 'invalid', 'threshold' => -5], 12 => ['mode' => 'immediate']]);
        $rule = $rules->forTool('run_audit');

        $this->assertSame(['run_audit'], array_keys($rules->rules));
        $this->assertSame(0, $rule->threshold);
        $this->assertSame('default@example.com', $rule->recipient(' default@example.com '));
        $this->assertSame('', $rule->recipient(null));
    }
}
