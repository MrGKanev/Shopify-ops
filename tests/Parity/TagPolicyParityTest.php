<?php

declare(strict_types=1);

use App\Domain\Reports\TagPolicyAnalyzer;
use PHPUnit\Framework\TestCase;

final class TagPolicyParityTest extends TestCase
{
    /**
     * Flags orders violating a configured required-tag or forbidden-tag-
     * combination policy. `tag_policy.json`/`config/tag-policy.php` are
     * both empty in this repo (dormant feature), so this fixture injects a
     * config directly to exercise the matching logic itself, which is real
     * code regardless of whether it's currently configured in production.
     */
    public function test_rows_match_legacy(): void
    {
        $config = [
            'required' => [
                ['name' => 'VIP needs priority', 'when' => ['vip'], 'must_have' => ['priority']],
            ],
            'forbidden' => [
                ['name' => 'Wholesale/retail conflict', 'tags' => ['wholesale', 'retail']],
            ],
        ];

        $orders = [
            // vip without priority -> required violation
            $this->order('1', ['vip']),
            // vip WITH priority -> no violation
            $this->order('2', ['vip', 'priority']),
            // not vip at all -> required rule doesn't even trigger
            $this->order('3', ['priority']),
            // wholesale + retail -> forbidden violation
            $this->order('4', ['wholesale', 'retail']),
            // only wholesale -> forbidden rule needs both
            $this->order('5', ['wholesale']),
            // both violations on the same order
            $this->order('6', ['vip', 'wholesale', 'retail']),
            // tags as a comma-separated string, not an array
            $this->order('7', 'vip, priority'),
        ];

        $legacyMethod = new ReflectionMethod(\OrderPolicyPageLoader::class, 'buildTagPolicyRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, $config);

        $laravelRows = (new TagPolicyAnalyzer())->analyze($orders, $config);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, array|string $tags): array
    {
        return ['id' => $orderNumber, 'name' => "#{$orderNumber}", 'email' => "c{$orderNumber}@example.com", 'created_at' => '2026-01-01T00:00:00Z', 'total_price' => '50.00', 'tags' => $tags];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, types: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'types' => array_column($r['violations'], 'type'),
        ], $rows);
    }
}
