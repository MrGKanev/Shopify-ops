<?php

namespace Tests\Feature;

use App\Application\Reports\CaptureShipmentBacklog;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class CaptureShipmentBacklogsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_manual_capture_is_independent_of_legacy_automation_and_preserves_first_observation(): void
    {
        $this->travelTo('2026-10-23 16:15:00 UTC');
        $store = Store::factory()->create(['scheduled_audit_enabled' => false, 'store_number' => '42', 'shopify_timezone' => 'Europe/Sofia']);
        Http::fake(['ssapi.shipstation.com/orders*' => function (Request $request) {
            $this->assertSame('42', (string) $request['storeId']);

            return Http::response($request['orderStatus'] === 'on_hold' ? $this->page([$this->order(2, '2026-10-10T09:15:00', 'on_hold')]) : $this->page([$this->order(1, '2026-10-22T09:15:00')]));
        }]);

        $first = app(CaptureShipmentBacklog::class)->handle($store);
        $second = app(CaptureShipmentBacklog::class)->handle($store);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $first->rows_found);
        $this->assertSame('2026-10-23T19:15:00+03:00', $first->result['captured_at']);
        $this->assertSame(['under_1_day' => 0, '1_2_days' => 1, '3_6_days' => 0, '7_plus_days' => 1, 'unknown' => 0], $first->result['age_buckets']);
        Http::assertSentCount(2);
    }

    public function test_confirmed_empty_backlog_is_zero(): void
    {
        $store = Store::factory()->create();
        Http::fake(['ssapi.shipstation.com/orders*' => Http::response($this->page([]))]);

        $snapshot = app(CaptureShipmentBacklog::class)->handle($store);

        $this->assertSame(0, $snapshot->rows_found);
        Http::assertSentCount(2);
    }

    public function test_source_dates_use_pacific_time_and_local_observation_day_with_unknown_ages_preserved(): void
    {
        $this->travelTo('2026-10-24 01:15:00 UTC');
        $store = Store::factory()->create(['store_number' => '42', 'shopify_timezone' => 'America/Los_Angeles']);
        Http::fake(['ssapi.shipstation.com/orders*' => fn (Request $request) => Http::response($request['orderStatus'] === 'on_hold' ? $this->page([]) : $this->page([
            $this->order(1, '2026-10-22T18:15:00.0000000'), $this->order(2, '2026-10-20T18:15:00'), $this->order(3, '2026-10-16T18:15:00'), $this->order(4, '2026-10-24T18:15:00'), $this->order(5, '2026-02-30T18:15:00'),
        ]))]);

        $snapshot = app(CaptureShipmentBacklog::class)->handle($store);

        $this->assertSame('2026-10-23', $snapshot->report_date->toDateString());
        $this->assertSame(['under_1_day' => 0, '1_2_days' => 1, '3_6_days' => 1, '7_plus_days' => 1, 'unknown' => 2], $snapshot->result['age_buckets']);
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidResponses')]
    public function test_incomplete_or_foreign_responses_do_not_write_snapshots(array $payload, string $exception): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        Http::fake(['ssapi.shipstation.com/orders*' => fn (Request $request) => Http::response($request['orderStatus'] === 'on_hold' ? $this->page([]) : $payload)]);

        try {
            $this->expectException($exception);
            app(CaptureShipmentBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
        }
    }

    public static function invalidResponses(): array
    {
        $order = ['orderId' => 1, 'orderStatus' => 'awaiting_shipment', 'orderDate' => '2026-10-01T00:00:00', 'advancedOptions' => ['storeId' => 42]];

        return [
            'missing collection' => [['page' => 1, 'pages' => 1, 'total' => 0], UnexpectedResponse::class],
            'missing page count' => [['orders' => [], 'page' => 1, 'total' => 0], UnexpectedResponse::class],
            'wrong page' => [['orders' => [], 'page' => 2, 'pages' => 1, 'total' => 0], UnexpectedResponse::class],
            'too many pages' => [['orders' => [], 'page' => 1, 'pages' => 21, 'total' => 0], UnexpectedResponse::class],
            'incomplete total' => [['orders' => [], 'page' => 1, 'pages' => 1, 'total' => 10], UnexpectedResponse::class],
            'foreign store' => [['orders' => [array_replace($order, ['advancedOptions' => ['storeId' => 99]])], 'page' => 1, 'pages' => 1, 'total' => 1], UnexpectedResponse::class],
            'wrong status' => [['orders' => [array_replace($order, ['orderStatus' => 'shipped'])], 'page' => 1, 'pages' => 1, 'total' => 1], UnexpectedResponse::class],
            'duplicate order' => [['orders' => [$order, $order], 'page' => 1, 'pages' => 1, 'total' => 2], UnexpectedValueException::class],
        ];
    }

    public function test_duplicate_ids_across_pages_are_not_saved(): void
    {
        $store = Store::factory()->create(['store_number' => '42']);
        Http::fake(['ssapi.shipstation.com/orders*' => function (Request $request) {
            return Http::response($request['orderStatus'] === 'on_hold' ? $this->page([]) : ['orders' => [$this->order(1, '2026-10-01T00:00:00')], 'page' => (int) $request['page'], 'pages' => 2, 'total' => 2]);
        }]);

        try {
            $this->expectException(UnexpectedValueException::class);
            app(CaptureShipmentBacklog::class)->handle($store);
        } finally {
            $this->assertDatabaseCount('audit_snapshots', 0);
        }
    }

    private function page(array $orders): array
    {
        return ['orders' => $orders, 'page' => 1, 'pages' => 1, 'total' => count($orders)];
    }

    private function order(int $id, string $date, string $status = 'awaiting_shipment'): array
    {
        return ['orderId' => $id, 'orderStatus' => $status, 'orderDate' => $date, 'advancedOptions' => ['storeId' => 42]];
    }
}
