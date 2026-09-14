<?php

declare(strict_types=1);

use App\Domain\Reports\AddressChangeAnalyzer;
use PHPUnit\Framework\TestCase;

final class AddressChangeParityTest extends TestCase
{
    public function test_address_change_rows_match_legacy(): void
    {
        $entries = [
            ['order' => $this->order(2, '#2', '2026-06-02T10:00:00Z'), 'changed_at' => '2026-06-03T12:00:00Z'],
            ['order' => $this->order(1, '#1', '2026-06-01T10:00:00Z'), 'changed_at' => '2026-06-01T11:30:00Z'],
        ];
        $method = new ReflectionMethod(OrderAnomalyPageLoader::class, 'buildAddrChangeRows');
        $legacy = $method->invoke(null, $entries);
        $orders = ['2' => $entries[0]['order'], '1' => $entries[1]['order']];
        $changes = ['2' => $entries[0]['changed_at'], '1' => $entries[1]['changed_at']];

        $this->assertSame($this->summarize($legacy), $this->summarize((new AddressChangeAnalyzer)->rows($orders, $changes)));
    }

    public function test_post_ship_address_change_rows_match_legacy(): void
    {
        $entries = [
            ['order' => $this->order(2, '#2', '2026-06-01T10:00:00Z'), 'changed_at' => '2026-06-04T12:00:00Z', 'fulfillment_at' => '2026-06-04T10:00:00Z'],
            ['order' => $this->order(1, '#1', '2026-06-01T10:00:00Z'), 'changed_at' => '2026-06-03T10:30:00Z', 'fulfillment_at' => '2026-06-03T10:00:00Z'],
        ];
        $method = new ReflectionMethod(FulfillmentIssuePageLoader::class, 'buildPostShipAddrChangeRows');
        $legacy = $method->invoke(null, $entries);
        $orders = [];
        $changes = [];
        foreach ($entries as $entry) {
            $id = (string) $entry['order']['id'];
            $orders[$id] = $entry['order'] + ['fulfillments' => [['created_at' => $entry['fulfillment_at']], ['created_at' => '2026-06-10T00:00:00Z']]];
            $changes[$id] = $entry['changed_at'];
        }

        $this->assertSame($this->summarize($legacy), $this->summarize((new AddressChangeAnalyzer)->postShipRows($orders, $changes)));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $name, string $createdAt): array
    {
        return [
            'id' => $id, 'name' => $name, 'created_at' => $createdAt, 'email' => 'customer@example.com',
            'total_price' => '99.50', 'financial_status' => 'paid', 'fulfillment_status' => 'unfulfilled',
            'shipping_address' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address1' => '1 Main St', 'city' => 'Boston', 'province_code' => 'MA', 'zip' => '02101', 'country_code' => 'US'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'shopify_id' => (string) $row['shopify_id'],
            'order_number' => $row['order_number'],
            'changed_at' => $row['changed_at'],
            'gap' => $row['gap_mins'] ?? $row['mins_after_ship'],
            'addr_name' => $row['addr_name'],
            'addr_line' => $row['addr_line'],
        ], $rows);
    }
}
