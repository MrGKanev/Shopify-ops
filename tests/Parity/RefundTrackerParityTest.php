<?php

declare(strict_types=1);

use App\Domain\Reports\RefundTrackerAnalyzer;
use PHPUnit\Framework\TestCase;

final class RefundTrackerParityTest extends TestCase
{
    public function test_rows_match_legacy(): void
    {
        $orders = [
            $this->order(1, '#1001', 'partially_refunded', '100.00', [['refund_line_items' => [['subtotal' => '12.50']]]]),
            $this->order(2, '#1002', 'refunded', '40.00', [['refund_line_items' => []]]),
            $this->order(3, '#1003', 'refunded', '30.00', [['refund_line_items' => [['subtotal' => '5.00']]]]),
        ];
        $shipStation = [
            ['orderNumber' => '1001-A', 'orderStatus' => 'awaiting_shipment'],
            ['orderNumber' => '1003', 'orderStatus' => 'shipped'],
        ];
        $method = new ReflectionMethod(OrderAnomalyPageLoader::class, 'buildRefundRows');
        $legacy = $method->invoke(null, $orders, $shipStation);
        $laravel = (new RefundTrackerAnalyzer)->analyze($orders, $shipStation);

        $this->assertSame($this->summarize($legacy), $this->summarize($laravel));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $name, string $status, string $total, array $refunds): array
    {
        return ['id' => $id, 'name' => $name, 'order_number' => ltrim($name, '#'), 'created_at' => '2026-06-01T10:00:00Z', 'email' => 'customer@example.com', 'financial_status' => $status, 'total_price' => $total, 'refunds' => $refunds];
    }

    /** @param list<array<string, mixed>> $rows */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'shopify_id' => (string) $row['shopify_id'],
            'order_number' => $row['order_number'],
            'total_price' => $row['total_price'],
            'refunded_amount' => $row['refunded_amount'],
            'statuses' => $row['ss_statuses'] ?? $row['shipstation_statuses'],
            'risk' => $row['risk'],
        ], $rows);
    }
}
