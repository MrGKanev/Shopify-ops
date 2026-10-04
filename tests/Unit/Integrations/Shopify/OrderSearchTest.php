<?php

namespace Tests\Unit\Integrations\Shopify;

use App\Integrations\Shopify\OrderSearch;
use App\Models\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderSearchTest extends TestCase
{
    /**
     * Pins every search string ShopifyAdminClient sends so a refactor cannot silently change a report's query.
     */
    #[DataProvider('clientSearches')]
    public function test_builds_the_exact_search_string_each_report_sends(string $expected, callable $build): void
    {
        $this->assertSame($expected, $build(new Store(['shopify_timezone' => 'UTC']))->toString());
    }

    /** @return array<string, array{string, callable(Store): OrderSearch}> */
    public static function clientSearches(): array
    {
        $range = 'created_at:>=2026-09-01T00:00:00Z created_at:<=2026-09-07T23:59:59Z';

        return [
            'high value' => [
                "status:any (financial_status:paid OR financial_status:partially_paid) (fulfillment_status:unfulfilled OR fulfillment_status:partial) {$range}",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->paidOrPartiallyPaid()->unfulfilledOrPartial()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'country mismatch and inventory aging' => [
                "status:any (financial_status:paid OR financial_status:partially_paid) {$range}",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->paidOrPartiallyPaid()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'paid orders created in range' => [
                "status:any financial_status:paid {$range}",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->paid()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'address check unfulfilled only' => [
                "status:any financial_status:paid {$range} fulfillment_status:unfulfilled",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->paid()->createdBetween($store, '2026-09-01', '2026-09-07')->unfulfilled(),
            ],
            'note flags' => [
                "status:any financial_status:paid fulfillment_status:unfulfilled {$range}",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->paid()->unfulfilled()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'duplicate orders and events' => [
                $range,
                fn (Store $store): OrderSearch => OrderSearch::created($store, '2026-09-01', '2026-09-07'),
            ],
            'any status created in range' => [
                "status:any {$range}",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'repeat refunds' => [
                "status:any (financial_status:refunded OR financial_status:partially_refunded) {$range}",
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->refundedOrPartiallyRefunded()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'returned items' => [
                'status:any (financial_status:refunded OR financial_status:partially_refunded) updated_at:>=2026-09-01T00:00:00Z',
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->refundedOrPartiallyRefunded()->updatedSince($store, '2026-09-01'),
            ],
            'fulfilled items and no tracking' => [
                'status:any updated_at:>=2026-09-01T00:00:00Z',
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->updatedSince($store, '2026-09-01'),
            ],
            'shipping margin' => [
                'status:any (fulfillment_status:fulfilled OR fulfillment_status:partial) updated_at:>=2026-09-01T00:00:00Z',
                fn (Store $store): OrderSearch => OrderSearch::anyStatus()->fulfilledOrPartial()->updatedSince($store, '2026-09-01'),
            ],
            'partial fulfillment' => [
                "status:open -financial_status:refunded fulfillment_status:partial {$range}",
                fn (Store $store): OrderSearch => OrderSearch::openStatus()->notRefunded()->partiallyFulfilled()->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'tag with escaped quotes and backslashes' => [
                "tag:\"VIP \\\"Plus\\\" \\\\ A\" {$range}",
                fn (Store $store): OrderSearch => OrderSearch::make()->tagged('VIP "Plus" \\ A')->createdBetween($store, '2026-09-01', '2026-09-07'),
            ],
            'tag without dates' => [
                'tag:"vip"',
                fn (Store $store): OrderSearch => OrderSearch::make()->tagged('vip')->createdBetween($store, null, null),
            ],
            'customer email' => [
                'email:"jane@example.com"',
                fn (Store $store): OrderSearch => OrderSearch::make()->email('  Jane@Example.com '),
            ],
            'metafield start date only' => [
                'created_at:>=2026-09-01T00:00:00Z',
                fn (Store $store): OrderSearch => OrderSearch::created($store, '2026-09-01', null),
            ],
            'metafield end date only' => [
                'created_at:<=2026-09-07T23:59:59Z',
                fn (Store $store): OrderSearch => OrderSearch::created($store, null, '2026-09-07'),
            ],
        ];
    }

    public function test_day_bounds_follow_the_shop_timezone_across_a_daylight_saving_change(): void
    {
        $store = new Store(['shopify_timezone' => 'Europe/Sofia']);

        $this->assertSame(
            'status:any created_at:>=2026-10-23T21:00:00Z created_at:<=2026-10-25T21:59:59Z',
            OrderSearch::anyStatus()->createdBetween($store, '2026-10-24', '2026-10-25')->toString(),
        );
        $this->assertSame('updated_at:>=2026-10-23T21:00:00Z', OrderSearch::make()->updatedSince($store, '2026-10-24')->toString());
    }

    public function test_an_unknown_timezone_falls_back_to_utc(): void
    {
        $this->assertSame(
            'created_at:>=2026-09-01T00:00:00Z',
            OrderSearch::created(new Store(['shopify_timezone' => 'Mars/Olympus']), '2026-09-01', null)->toString(),
        );
    }

    public function test_is_immutable_and_reports_when_empty(): void
    {
        $base = OrderSearch::anyStatus();
        $paid = $base->paid();

        $this->assertSame('status:any', $base->toString());
        $this->assertSame('status:any financial_status:paid', $paid->toString());
        $this->assertTrue(OrderSearch::make()->isEmpty());
        $this->assertTrue(OrderSearch::created(new Store, null, null)->isEmpty());
        $this->assertFalse($base->isEmpty());
    }
}
