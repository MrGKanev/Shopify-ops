<?php

namespace Tests\Feature;

use App\Application\Reports\QueuedReportRunner;
use App\Application\Reports\ReportResult;
use App\Application\Reports\RunOrderContributionReport;
use App\Domain\Reports\OrderContributionAnalyzer;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Jobs\RunQueuedReport;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrderContributionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reported_contribution_counts_each_label_once_and_includes_returns_and_reships(): void
    {
        $labels = [$this->label('1', 5), $this->label('2', 7), $this->label('3', 4, ['isReturnLabel' => true]), $this->label('1', 5)];

        $row = app(OrderContributionAnalyzer::class)->analyze($this->order(), $this->analytics(), $labels, 'USD');

        $this->assertSame(110.0, $row['revenue']);
        $this->assertSame(40.0, $row['cogs']);
        $this->assertSame(3.0, $row['fees']);
        $this->assertSame(16.0, $row['shipping']);
        $this->assertSame(51.0, $row['contribution']);
        $this->assertCount(3, $row['labels']);
        $this->assertSame('reported_basis', $row['status']);
    }

    #[TestWith(['cost', 'missing'])]
    #[TestWith(['cost', 'zero'])]
    #[TestWith(['shipping', 'missing_currency'])]
    #[TestWith(['shipping', 'different_currency'])]
    #[TestWith(['shipping', 'missing_cost'])]
    #[TestWith(['shipping', 'voided'])]
    #[TestWith(['fees', 'different_currency'])]
    #[TestWith(['fees', 'missing'])]
    #[TestWith(['fees', 'other_gateway'])]
    public function test_missing_costs_currency_and_voids_never_become_zero_or_complete_margin(string $source, string $case): void
    {
        $order = $this->order();
        $analytics = $this->analytics();
        $label = $this->label('1', 5);
        if ($source === 'cost') {
            $analytics[$case === 'zero' ? 'cost_of_goods_sold' : 'net_sales_without_cost_recorded'] = $case === 'zero' ? 0 : 20;
        } elseif ($source === 'shipping') {
            $label = match ($case) {
                'missing_currency' => array_diff_key($label, ['currencyCode' => true]),
                'different_currency' => [...$label, 'currencyCode' => 'EUR'],
                'missing_cost' => array_diff_key($label, ['insuranceCost' => true]),
                'voided' => [...$label, 'voided' => true],
            };
        } else {
            if ($case === 'missing') {
                $order['transactions'][0]['fees'] = [];
            } elseif ($case === 'other_gateway') {
                $order['transactions'][0]['gateway'] = 'paypal';
            } else {
                $order['transactions'][0]['fees'][0]['amount']['currencyCode'] = 'EUR';
            }
        }

        $row = app(OrderContributionAnalyzer::class)->analyze($order, $analytics, [$label], 'USD');

        $this->assertNull($row['contribution']);
        $this->assertSame('incomplete', $row['status']);
        $this->assertNotEmpty($row['missing']);
    }

    public function test_conflicting_duplicate_label_and_partial_fees_do_not_produce_complete_margin(): void
    {
        $order = $this->order();
        $order['transactions'][] = [...$order['transactions'][0], 'id' => 't2', 'gateway' => 'paypal'];

        $row = app(OrderContributionAnalyzer::class)->analyze($order, $this->analytics(), [$this->label('1', 5), $this->label('1', 5, ['voided' => true])], 'USD');

        $this->assertNull($row['shipping']);
        $this->assertNull($row['fees']);
        $this->assertSame(3.0, $row['observed_fees']);
        $this->assertNull($row['contribution']);
    }

    public function test_labels_are_matched_by_exact_number_store_and_order_id(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->fakeShopify($store);
        $ss = Mockery::mock(ShipStationClientContract::class);
        $ss->shouldReceive('findByOrderNumber')->once()->with('WEB-1001', 12)->andReturn([
            ['orderId' => 77, 'orderNumber' => 'WEB-1001', 'advancedOptions' => ['storeId' => 12]],
            ['orderId' => 88, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 12]],
            ['orderId' => 99, 'orderNumber' => 'WEB-1001', 'advancedOptions' => ['storeId' => 13]],
        ]);
        $ss->shouldReceive('getOrderCostShipments')->once()->with(77, 12, '2026-10-01', '2026-10-07')->andReturn([
            $this->label('1', 5),
            $this->label('2', 100, ['orderId' => 99]),
        ]);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->once()->andReturn($ss);

        $result = app(RunOrderContributionReport::class)->handle($store, '2026-10-01', '2026-10-02');

        $this->assertSame(62.0, $result->rows[0]['contribution']);
        $this->assertCount(1, $result->rows[0]['labels']);
        $this->assertSame(1, $result->meta['reported']);
    }

    public function test_screen_shows_unknown_currency_and_preserves_shopify_basis_without_combining(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->fakeShopify($store);
        $ss = Mockery::mock(ShipStationClientContract::class);
        $ss->shouldReceive('findByOrderNumber')->andReturn([['orderId' => 77, 'orderNumber' => 'WEB-1001', 'advancedOptions' => ['storeId' => 12]]]);
        $ss->shouldReceive('getOrderCostShipments')->andReturn([array_diff_key($this->label('1', 5), ['currencyCode' => true])]);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->andReturn($ss);

        $response = $this->actingAs($operator)->post(route('reports.order-contribution.store'), ['start_date' => '2026-10-01', 'end_date' => '2026-10-02']);

        $response->assertSeeText('Currency unconfirmed')->assertSeeText('Shopify basis before ShipStation costs')->assertSeeText('Incomplete');
        $result = $store->reportRuns()->sole()->result();
        $this->assertNull($result->rows[0]['contribution']);
        $this->assertSame(67.0, $result->rows[0]['shopify_contribution']);
        $this->assertDatabaseCount('push_logs', 0);
    }

    public function test_report_is_queued_and_access_requires_an_operator(): void
    {
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('reports.order-contribution'))->assertForbidden();
        [$operator, $store] = $this->userWithStore(true);
        Queue::fake([RunQueuedReport::class]);

        $this->actingAs($operator)->post(route('reports.order-contribution.store'), ['start_date' => '2026-10-01', 'end_date' => '2026-10-02'])->assertRedirect();

        $this->assertSame('queued', $store->reportRuns()->sole()->status);
        Queue::assertPushed(RunQueuedReport::class, 1);
    }

    public function test_report_validates_dates_and_does_not_query_with_invalid_input(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->post(route('reports.order-contribution.store'), ['start_date' => 'bad', 'end_date' => '2026-10-01'])->assertSessionHasErrors('start_date');
    }

    public function test_refund_fee_credit_is_counted_once_without_subtracting_refund_revenue_again(): void
    {
        $order = $this->order();
        $order['transactions'][] = ['id' => 'refund1', 'kind' => 'REFUND', 'status' => 'SUCCESS', 'gateway' => 'shopify_payments', 'test' => false, 'fees' => [['id' => 'credit1', 'amount' => ['amount' => '-1', 'currencyCode' => 'USD'], 'taxAmount' => ['amount' => '0', 'currencyCode' => 'USD']]]];
        $order['transactions'][] = $order['transactions'][1];
        $analytics = [...$this->analytics(), 'net_sales' => '80', 'net_sales_with_cost_recorded' => '80'];

        $row = app(OrderContributionAnalyzer::class)->analyze($order, $analytics, [$this->label('1', 5)], 'USD');

        $this->assertSame(90.0, $row['revenue']);
        $this->assertSame(2.0, $row['fees']);
        $this->assertSame(43.0, $row['contribution']);
    }

    public function test_view_escapes_order_and_label_content(): void
    {
        $row = app(OrderContributionAnalyzer::class)->analyze([...$this->order(), 'name' => '<img src=x onerror=alert(1)>'], $this->analytics(), [$this->label('1', 5, ['carrierCode' => '<script>alert(2)</script>'])], 'USD');
        $result = new ReportResult([$row], 1, meta: ['checkedAt' => '2026-10-07T12:00:00Z', 'timezone' => 'Europe/Sofia', 'reported' => 1, 'incomplete' => 0, 'coverage' => [], 'nextAfter' => null]);

        [$operator, $store] = $this->userWithStore(true);
        $run = app(QueuedReportRunner::class)->createRun($store, 'order_contribution', RunOrderContributionReport::class, ['2026-10-01', '2026-10-02', null], '2026-10-01', '2026-10-02', $operator->id);
        $run->storeResult($result);

        $this->actingAs($operator)->get(route('reports.order-contribution.result', ['start_date' => '2026-10-01', 'end_date' => '2026-10-02']))
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('<script>alert(2)', false)
            ->assertSee('&lt;script&gt;', false);
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return ['id' => 'gid://shopify/Order/1001', 'legacyResourceId' => '1001', 'name' => '#WEB-1001', 'createdAt' => '2026-10-01T10:00:00Z', 'fees_available' => true, 'transactions' => [['id' => 't1', 'kind' => 'SALE', 'status' => 'SUCCESS', 'gateway' => 'shopify_payments', 'test' => false, 'fees' => [['id' => 'fee1', 'amount' => ['amount' => '2.5', 'currencyCode' => 'USD'], 'taxAmount' => ['amount' => '0.5', 'currencyCode' => 'USD']]]]]];
    }

    /** @return array<string, mixed> */
    private function analytics(): array
    {
        return ['order_id' => '1001', 'net_sales' => '100', 'shipping_charges' => '10', 'cost_of_goods_sold' => '40', 'net_sales_with_cost_recorded' => '100', 'net_sales_without_cost_recorded' => '0'];
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed> */
    private function label(string $id, float $cost, array $extra = []): array
    {
        return [...['shipmentId' => $id, 'orderId' => 77, 'orderNumber' => 'WEB-1001', 'shipmentCost' => $cost, 'insuranceCost' => 0, 'voided' => false, 'isReturnLabel' => false, 'currencyCode' => 'USD'], ...$extra];
    }

    private function fakeShopify(Store $store): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $order = $this->order();
        $transport->shouldReceive('graphql')->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), Mockery::pattern('/query OrderContributionShop/'))->andReturn(['data' => ['shop' => ['currencyCode' => 'USD', 'ianaTimezone' => 'Europe/Sofia'], 'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_reports']]]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionOrders/'), Mockery::any())->andReturn(['data' => ['orders' => ['edges' => [['node' => $order]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/query OrderContributionFees/'), ['id' => $order['id']])->andReturn(['data' => ['order' => ['id' => $order['id'], 'transactions' => $order['transactions']]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/query OrderContributionAnalytics/'), Mockery::any())->andReturn(['data' => ['shopifyqlQuery' => ['parseErrors' => [], 'tableData' => ['columns' => array_map(fn (string $key): array => ['name' => $key, 'dataType' => $key === 'order_id' ? 'IDENTITY' : 'MONEY'], array_keys($this->analytics())), 'rows' => [$this->analytics()]]]]]);
    }
}
