<?php

declare(strict_types=1);

use App\Domain\Reports\OrderEditAnalyzer;
use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\OrderEventAudits;

final class OrderEditParityTest extends TestCase
{
    public function test_event_grouping_and_rows_match_legacy(): void
    {
        $events = [
            ['subject_id' => 1, 'verb' => 'edit_complete', 'message' => 'line item was edited', 'created_at' => '2026-06-01T11:00:00Z'],
            ['subject_id' => 1, 'verb' => 'edit_complete', 'message' => 'line item was edited', 'created_at' => '2026-06-01T12:00:00Z'],
            ['subject_id' => 1, 'verb' => 'edit_complete', 'message' => 'discount was added', 'created_at' => '2026-06-01T13:00:00Z'],
            ['subject_id' => 2, 'verb' => 'confirmed', 'message' => 'Order was confirmed', 'created_at' => '2026-06-02T12:00:00Z'],
        ];
        $orders = [
            '1' => ['name' => '#1', 'created_at' => '2026-06-01T10:00:00Z', 'email' => 'one@example.com', 'total_price' => '12.50', 'financial_status' => 'paid', 'fulfillment_status' => null],
        ];

        $legacyGroups = OrderEventAudits::groupEditEventsByOrder($events);
        $legacyRows = OrderEventAudits::buildEditedOrderRows($orders, $legacyGroups);
        $laravel = new OrderEditAnalyzer(new ShopifyOrderEventNormalizer);

        $this->assertSame($legacyGroups, $laravel->group($events));
        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravel->rows($orders, $laravel->group($events))));
    }

    /** @param list<array<string, mixed>> $rows */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'shopify_id' => $row['shopify_id'],
            'order_number' => $row['order_number'],
            'edited_at' => $row['edited_at'],
            'diff_mins' => $row['diff_mins'],
            'edit_summary' => $row['edit_summary'],
        ], $rows);
    }
}
