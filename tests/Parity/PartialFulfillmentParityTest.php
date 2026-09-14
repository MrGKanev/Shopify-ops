<?php

declare(strict_types=1);

use App\Domain\Reports\PartialFulfillmentAnalyzer;
use PHPUnit\Framework\TestCase;

final class PartialFulfillmentParityTest extends TestCase
{
    /**
     * Flags an order that's been partially shipped and then stalled --
     * ops needs to know both how long it's been stuck and what's still
     * owed to the customer, so both the stall-clock basis (last
     * fulfillment, or order date if never fulfilled at all) and the
     * still-fulfillable item filter have to agree with legacy.
     */
    public function test_rows_match_legacy(): void
    {
        $now = time();
        $threshold = 7;

        $orders = [
            // #7001: never fulfilled at all -> stall clock runs from created_at
            $this->order('7001', $now - 10 * 86400, fulfillments: [], items: [$this->item('WIDGET', 1)]),
            // #7002: fulfilled once, 10 days ago -> stall clock runs from that fulfillment
            $this->order('7002', $now - 30 * 86400, fulfillments: [$now - 10 * 86400], items: [$this->item('GADGET', 1)]),
            // #7003: fulfilled twice -> stall clock runs from the LATEST fulfillment, not the first
            $this->order('7003', $now - 30 * 86400, fulfillments: [$now - 20 * 86400, $now - 9 * 86400], items: [$this->item('GADGET', 1)]),
            // #7004: stalled long enough, but nothing left to fulfill -> excluded
            $this->order('7004', $now - 10 * 86400, fulfillments: [], items: [$this->item('WIDGET', 0)]),
            // #7005: under threshold -> excluded
            $this->order('7005', $now - 1 * 86400, fulfillments: [], items: [$this->item('WIDGET', 1)]),
        ];

        $legacyMethod = new ReflectionMethod(\SimpleScanPageLoader::class, 'buildPartialFulfillRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, $threshold, $now);

        $laravelRows = (new PartialFulfillmentAnalyzer())->analyze($orders, $threshold, $now);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function item(string $sku, int $fulfillableQty): array
    {
        return ['sku' => $sku, 'name' => $sku, 'fulfillable_quantity' => $fulfillableQty];
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, int $createdAtTs, array $fulfillments, array $items): array
    {
        return [
            'id' => $orderNumber,
            'name' => "#{$orderNumber}",
            'email' => "order{$orderNumber}@example.com",
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $createdAtTs),
            'total_price' => '199.00',
            'financial_status' => 'paid',
            'line_items' => $items,
            'fulfillments' => array_map(static fn (int $ts): array => ['created_at' => gmdate('Y-m-d\TH:i:s\Z', $ts)], $fulfillments),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, days_stalled: mixed, last_fulfilled: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'days_stalled' => $r['days_stalled'],
            'last_fulfilled' => $r['last_fulfilled'],
        ], $rows);
    }
}
