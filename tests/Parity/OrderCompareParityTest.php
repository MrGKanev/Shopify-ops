<?php

declare(strict_types=1);

use App\Domain\Orders\ShopifyOrderComparator;
use PHPUnit\Framework\TestCase;

final class OrderCompareParityTest extends TestCase
{
    public function test_visible_comparison_rows_match_legacy(): void
    {
        $a = $this->order('#1001', 'buyer@example.com', '100.00', ['vip']);
        $b = $this->order('#1002', 'other@example.com', '95.00', ['priority']);
        $legacy = $this->legacyRows($a, $b, [['orderStatus' => 'shipped']], [['orderStatus' => 'on_hold']]);
        $laravel = (new ShopifyOrderComparator)->compare($a, $b, '1001', '1002', true, 'shipped', 'on_hold');

        $legacy = array_map(static function (array $row): array {
            $row['label'] = str_replace('Fulfilment', 'Fulfillment', $row['label']);
            $row['different'] = (string) $row['a'] !== (string) $row['b'];

            return $row;
        }, $legacy);

        $this->assertSame($legacy, $laravel['rows']);
        $this->assertSame(count(array_filter($legacy, static fn (array $row): bool => $row['different'])), $laravel['difference_count']);
    }

    /** @return list<array<string, mixed>> */
    private function legacyRows(array $a, array $b, array $ssA, array $ssB): array
    {
        $compareError = '';
        $compareA = '1001';
        $compareB = '1002';
        $shopifyAdminBase = 'https://example.myshopify.com/admin/orders';
        $compareResult = ['a' => ['shopify' => $a, 'ss' => $ssA, 'num' => $compareA], 'b' => ['shopify' => $b, 'ss' => $ssB, 'num' => $compareB]];
        ob_start();
        require dirname(__DIR__, 2).'/views/compare.php';
        ob_end_clean();

        return $rows;
    }

    /** @return array<string, mixed> */
    private function order(string $name, string $email, string $total, array $tags): array
    {
        return [
            'id' => ltrim($name, '#'), 'name' => $name, 'created_at' => '2026-06-01T10:00:00Z', 'email' => $email,
            'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'total_price' => $total,
            'line_items' => [['quantity' => 2, 'title' => 'Widget', 'variant_title' => 'Blue']],
            'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main', 'city' => 'Sofia', 'country_code' => 'BG'],
            'tags' => $tags, 'note' => 'Handle carefully',
        ];
    }
}
