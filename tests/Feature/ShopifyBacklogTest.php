<?php

namespace Tests\Feature;

use App\Application\Reports\CaptureShopifyBacklog;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\AuditSnapshot;
use App\Models\ReportSchedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class ShopifyBacklogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_capture_counts_remaining_physical_units_separates_currencies_and_preserves_the_first_local_observation(): void
    {
        $this->travelTo('2026-10-23 22:15:00 UTC');
        $store = Store::factory()->create(['shopify_timezone' => 'Europe/Sofia']);
        $first = $this->fulfillment(1, 1, 1, 2, '0.1');
        $split = $this->fulfillment(2, 1, 2, 1, '0.2', 'USD', 'ON_HOLD');
        $euro = $this->fulfillment(3, 2, 3, 3, '7.123456789', 'EUR', 'SCHEDULED');
        $digital = $this->fulfillment(4, 3, 4, 100, '99');
        $digital['lineItems']['nodes'][0]['requiresShipping'] = false;
        $cancelled = $this->fulfillment(5, 4, 5, 100, '99');
        $cancelled['order']['cancelledAt'] = '2026-10-23T12:00:00Z';
        $test = $this->fulfillment(6, 5, 6, 100, '99');
        $test['order']['test'] = true;
        $closed = $this->fulfillment(7, 6, 7, 100, '99', 'USD', 'CLOSED');
        $this->candidates([$first, $split, $euro, $digital, $cancelled, $test, $closed]);

        $snapshot = app(CaptureShopifyBacklog::class)->handle($store);
        $again = app(CaptureShopifyBacklog::class)->handle($store);

        $this->assertSame($snapshot->id, $again->id);
        $this->assertSame('2026-10-24', $snapshot->report_date->toDateString());
        $this->assertSame('2026-10-24T01:15:00+03:00', $snapshot->result['captured_at']);
        $this->assertSame(2, $snapshot->rows_found);
        $this->assertSame(3, $snapshot->result['fulfillment_orders']);
        $this->assertSame(6, $snapshot->result['remaining_units']);
        $this->assertSame(['EUR', 'USD'], array_column($snapshot->result['currencies'], 'currency'));
        $this->assertSame(['21.370370367', '0.4'], array_column($snapshot->result['currencies'], 'estimated_value'));
        $this->assertSame(1, $snapshot->result['currencies'][1]['orders']);
    }

    public function test_missing_or_conflicting_prices_never_produce_a_complete_value(): void
    {
        $store = Store::factory()->create();
        $bad = $this->fulfillment(1, 1, 1, 3, '1.2', 'USD');
        $bad['lineItems']['nodes'][0]['lineItem']['discountedUnitPriceAfterAllDiscountsSet']['presentmentMoney']['currencyCode'] = 'EUR';
        $missing = $this->fulfillment(2, 1, 2, 1, '1.2', 'USD');
        unset($missing['lineItems']['nodes'][0]['lineItem']);
        $this->candidates([$bad, $missing, $this->fulfillment(3, 2, 3, 2, '0.2')]);

        $snapshot = app(CaptureShopifyBacklog::class)->handle($store);

        $this->assertSame(6, $snapshot->result['remaining_units']);
        $this->assertSame(4, $snapshot->result['unpriced_units']);
        $this->assertNull($snapshot->result['currencies'][0]['estimated_value']);
        $this->assertSame('0.4', $snapshot->result['currencies'][0]['known_value']);
    }

    public function test_confirmed_empty_coverage_is_zero_and_an_account_change_does_not_reuse_it(): void
    {
        $store = Store::factory()->create();
        $this->candidates([]);
        $snapshot = app(CaptureShopifyBacklog::class)->handle($store);
        $this->assertSame(0, $snapshot->rows_found);
        $this->assertSame(0, $snapshot->result['remaining_units']);
        $store->update(['shopify_access_token' => 'different-token']);
        $this->expectException(LogicException::class);
        app(CaptureShopifyBacklog::class)->handle($store);
    }

    public function test_trends_compare_only_matching_weekly_coverage_and_exports_are_store_scoped_and_formula_safe(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $other = Store::factory()->create();
        $this->snapshot($store, '2026-10-16', 10);
        $today = $this->snapshot($store, '2026-10-23', 4);
        $this->snapshot($store, '2026-10-17', 10, ['read_third_party_fulfillment_orders']);
        $this->snapshot($store, '2026-10-24', 4);
        $changedAccount = $this->snapshot($store, '2026-10-18', 10);
        $changedAccount->update(['result' => [...$changedAccount->result, 'account_fingerprint' => 'another-account']]);
        $this->snapshot($store, '2026-10-25', 4);
        $changedTimezone = $this->snapshot($store, '2026-10-19', 10);
        $changedTimezone->update(['result' => [...$changedTimezone->result, 'timezone' => 'Europe/Sofia']]);
        $this->snapshot($store, '2026-10-26', 4);
        $foreign = $this->snapshot($other, '2026-10-23', 999);

        $this->actingAs($operator)->get('/report-trends?start_date=2026-10-23&end_date=2026-10-27')->assertViewHas('shopifyBacklogRows', fn (array $rows): bool => array_column($rows, 'remaining_units') === [4, 4, 4, 4] && array_column($rows, 'weekly_units_delta') === [-6, null, null, null])->assertSeeText('Shopify fulfillment backlog')->assertDontSeeText('999');
        $this->get(route('saved-reports.show', $today))->assertRedirect(route('report-trends.index', ['start_date' => '2026-10-23', 'end_date' => '2026-10-23']));
        $export = $this->get(route('saved-reports.export', $today));
        $export->assertDownload('shopify-backlog-2026-10-23.csv');
        $this->assertStringContainsString("'=ORDER", $export->streamedContent());
        $this->assertStringContainsString('Estimated remaining merchandise value', $export->streamedContent());
        $this->get(route('saved-reports.export', $foreign))->assertNotFound();
        $this->get(route('saved-reports.show', $foreign))->assertNotFound();
    }

    public function test_a_paused_shopify_schedule_can_run_manually_without_enabling_other_collection(): void
    {
        $this->travelTo('2026-10-23 08:00:00 UTC');
        $admin = User::factory()->admin()->create();
        $store = Store::factory()->create(['shopify_timezone' => 'UTC']);
        $admin->stores()->attach($store);
        $otherSchedule = ReportSchedule::factory()->create(['store_id' => $store->id, 'user_id' => $admin->id, 'tool' => 'shipment_backlog', 'enabled' => false]);
        $this->actingAs($admin)->get(route('admin.report-schedules.create', ['tool' => 'shopify_backlog']))->assertSeeText('Shopify fulfillment backlog');
        $this->post(route('admin.report-schedules.store'), ['name' => 'Shopify remaining work', 'tool' => 'shopify_backlog', 'enabled' => false, 'frequency' => 'daily', 'time' => '07:00', 'timezone' => 'UTC', 'weekday' => 1, 'month_day' => 1, 'lookback_days' => 30, 'end_offset_days' => 1, 'notification_enabled' => false, 'notification_threshold' => 1])->assertRedirect(route('admin.report-schedules.index'));
        $schedule = $store->reportSchedules()->where('tool', 'shopify_backlog')->sole();
        $this->assertFalse($schedule->enabled);
        $this->assertNull($schedule->next_run_at);
        $this->candidates([$this->fulfillment(1, 1, 1, 2, '5')]);

        $this->post(route('admin.report-schedules.run', $schedule))->assertRedirect();

        $run = $schedule->runs()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame([], $run->arguments);
        $this->assertFalse($schedule->fresh()->enabled);
        $this->assertFalse($otherSchedule->fresh()->enabled);
        $this->assertSame(1, $store->auditSnapshots()->where('tool', 'shopify_backlog')->count());
        $this->get(route('admin.report-schedules.result', [$schedule, $run]))->assertRedirect(route('report-trends.index', ['start_date' => '2026-10-23', 'end_date' => '2026-10-23']));
    }

    public function test_the_default_trend_period_includes_today_in_the_store_timezone(): void
    {
        $this->travelTo('2026-10-23 22:15:00 UTC');
        [$operator, $store] = $this->userWithStore(true, ['shopify_timezone' => 'Europe/Sofia']);
        $this->candidates([$this->fulfillment(1, 1, 1, 2, '5')]);
        app(CaptureShopifyBacklog::class)->handle($store);

        $this->actingAs($operator)->get(route('report-trends.index'))->assertViewHas('endDate', '2026-10-24')->assertViewHas('shopifyBacklogRows', fn (array $rows): bool => count($rows) === 1 && $rows[0]['date'] === '2026-10-24');
    }

    private function candidates(array $orders): void
    {
        $this->mock(ShopifyOrders::class)->shouldReceive('shipmentBacklogCandidates')->once()->andReturn(['fulfillment_orders' => $orders, 'scopes' => ['read_merchant_managed_fulfillment_orders', 'read_orders'], 'pages' => 1, 'truncated' => false]);
    }

    private function fulfillment(int $id, int $orderId, int $itemId, int $remaining, string $price, string $currency = 'USD', string $status = 'OPEN'): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrder/'.$id, 'status' => $status, 'order' => ['id' => 'gid://shopify/Order/'.$orderId, 'name' => '#'.$orderId, 'test' => false, 'cancelledAt' => null, 'presentmentCurrencyCode' => $currency, 'taxesIncluded' => false], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/'.$itemId, 'remainingQuantity' => $remaining, 'totalQuantity' => $remaining + 10, 'requiresShipping' => true, 'lineItem' => ['discountedUnitPriceAfterAllDiscountsSet' => ['presentmentMoney' => ['amount' => $price, 'currencyCode' => $currency]]]]], 'pageInfo' => ['hasNextPage' => false]]];
    }

    private function snapshot(Store $store, string $date, int $quantity, array $scopes = ['read_merchant_managed_fulfillment_orders']): AuditSnapshot
    {
        return $store->auditSnapshots()->create(['tool' => 'shopify_backlog', 'report_date' => $date, 'start_date' => $date, 'end_date' => $date, 'rows_found' => 1, 'result' => ['orders' => 1, 'fulfillment_orders' => 1, 'remaining_units' => $quantity, 'unpriced_units' => 0, 'currencies' => [['currency' => 'USD', 'estimated_value' => '10']], 'captured_at' => $date.'T08:00:00Z', 'completed_at' => $date.'T08:00:00Z', 'timezone' => 'UTC', 'scopes' => $scopes, 'account_fingerprint' => 'same-account', 'rows' => [['order_number' => '=ORDER', 'order_id' => 'gid://shopify/Order/1', 'fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN', 'remaining_units' => $quantity, 'currency' => 'USD', 'estimated_value' => '10', 'known_value' => '10', 'unpriced_units' => 0, 'taxes_included' => false]]]]);
    }
}
