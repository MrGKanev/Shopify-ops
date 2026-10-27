<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ShipmentBacklogAnalyzer;
use App\Domain\Reports\ShippingMarginAnalyzer;
use App\Domain\Reports\ShopifyBacklogAnalyzer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class BacklogAnalyzersEmptyDataTest extends TestCase
{
    private const NOW = '2026-10-24T12:00:00Z';

    public function test_an_empty_shipstation_backlog_is_an_explicit_zero_with_every_age_bucket(): void
    {
        $result = (new ShipmentBacklogAnalyzer)->analyze([], CarbonImmutable::parse(self::NOW));

        $this->assertSame(['awaiting' => 0, 'on_hold' => 0, 'age_buckets' => ['under_1_day' => 0, '1_2_days' => 0, '3_6_days' => 0, '7_plus_days' => 0, 'unknown' => 0]], $result);
    }

    public function test_shipstation_age_buckets_change_exactly_at_whole_days(): void
    {
        $order = fn (int $id, string $date): array => ['orderId' => $id, 'orderStatus' => 'awaiting_shipment', 'orderDate' => $date];
        // The check time is 05:00 Pacific; a source date without an offset is read as Pacific time (UTC-7 in October).
        $result = (new ShipmentBacklogAnalyzer)->analyze([
            $order(1, '2026-10-24T05:00:00'), $order(2, '2026-10-23T05:00:01'), $order(3, '2026-10-23T05:00:00'), $order(4, '2026-10-21T05:00:01'),
            $order(5, '2026-10-21T05:00:00'), $order(6, '2026-10-17T05:00:01'), $order(7, '2026-10-17T05:00:00'), $order(8, '2026-10-24T05:00:01'),
        ], CarbonImmutable::parse(self::NOW));

        $this->assertSame(['under_1_day' => 2, '1_2_days' => 2, '3_6_days' => 2, '7_plus_days' => 1, 'unknown' => 1], $result['age_buckets']);
        $this->assertSame(8, $result['awaiting']);
    }

    public function test_a_shipstation_backlog_rejects_duplicate_ids_and_unexpected_statuses(): void
    {
        $analyzer = new ShipmentBacklogAnalyzer;
        foreach ([[['orderId' => 1, 'orderStatus' => 'awaiting_shipment'], ['orderId' => '1', 'orderStatus' => 'on_hold']], [['orderId' => 1, 'orderStatus' => 'shipped']], [['orderId' => 0, 'orderStatus' => 'on_hold']], [['orderStatus' => 'on_hold']]] as $orders) {
            try {
                $analyzer->analyze($orders, CarbonImmutable::parse(self::NOW));
                $this->fail('Unconfirmed backlog orders must not be counted.');
            } catch (UnexpectedValueException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_an_empty_shopify_backlog_is_an_explicit_zero(): void
    {
        $this->assertSame(['rows' => [], 'orders' => 0, 'fulfillment_orders' => 0, 'remaining_units' => 0, 'unpriced_units' => 0, 'currencies' => []], (new ShopifyBacklogAnalyzer)->analyze([]));
    }

    public function test_a_shopify_backlog_made_only_of_closed_test_or_cancelled_orders_is_zero_not_unknown(): void
    {
        $closed = $this->fulfillment(1, 'CLOSED');
        $test = $this->fulfillment(2, 'OPEN', ['test' => true]);
        $cancelled = $this->fulfillment(3, 'OPEN', ['cancelledAt' => '2026-10-01T00:00:00Z']);

        $result = (new ShopifyBacklogAnalyzer)->analyze([$closed, $test, $cancelled]);

        $this->assertSame([0, 0, 0, []], [$result['orders'], $result['fulfillment_orders'], $result['remaining_units'], $result['currencies']]);
    }

    public function test_incomplete_shopify_line_items_fail_instead_of_looking_like_an_empty_backlog(): void
    {
        $incomplete = $this->fulfillment(1, 'OPEN');
        $incomplete['lineItems']['pageInfo']['hasNextPage'] = true;

        $this->expectException(UnexpectedValueException::class);
        (new ShopifyBacklogAnalyzer)->analyze([$incomplete]);
    }

    public function test_the_margin_report_has_no_rows_without_labels_or_without_a_unique_order(): void
    {
        $analyzer = new ShippingMarginAnalyzer;
        $label = ['orderId' => 1, 'orderNumber' => '1001', 'shipmentCost' => 20, 'insuranceCost' => 0, 'carrierCode' => 'ups', 'serviceCode' => 'ground'];
        $order = ['order_number' => '1001', 'shipping_lines' => [['price' => '1.00']]];

        $this->assertSame([], $analyzer->analyze([], [$order], 0));
        $this->assertSame([], $analyzer->analyze([$label], [], 0));
        $this->assertSame([], $analyzer->analyze([], [], 0));
        $this->assertSame([], $analyzer->analyze([$label], [$order, $order], 0));
        $this->assertSame([], $analyzer->byCarrier([]));
    }

    /** @return array<string, mixed> */
    private function fulfillment(int $id, string $status, array $order = []): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrder/'.$id, 'status' => $status, 'order' => $order + ['id' => 'gid://shopify/Order/'.$id, 'name' => '#'.$id, 'test' => false, 'cancelledAt' => null, 'presentmentCurrencyCode' => 'USD', 'taxesIncluded' => false], 'lineItems' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]];
    }
}
