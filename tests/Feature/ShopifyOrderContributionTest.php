<?php

namespace Tests\Feature;

use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClient;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyOrderContribution;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class ShopifyOrderContributionTest extends TestCase
{
    public function test_analytics_use_order_ids_store_currency_and_later_adjustments_without_current_unit_cost(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $store = Store::factory()->make();
        Http::preventStrayRequests();
        Http::fake(['https://'.$store->shopify_store.'.myshopify.com/admin/api/2026-07/graphql.json' => function (Request $request) {
            $query = $request['query'];
            if (str_contains($query, 'OrderContributionShop')) {
                return Http::response($this->shop());
            }
            if (str_contains($query, 'OrderContributionOrders')) {
                return Http::response(['data' => ['orders' => ['edges' => [['node' => $this->order()]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
            }
            if (str_contains($query, 'OrderContributionFees')) {
                return Http::response(['data' => ['order' => ['id' => 'gid://shopify/Order/1001', 'transactions' => []]]]);
            }

            return Http::response($this->analyticsResponse());
        }]);

        $data = app(ShopifyOrderContribution::class)->collect($store, '2026-10-01', '2026-10-02');

        $this->assertSame('USD', $data['currency']);
        $this->assertSame('Europe/Sofia', $data['timezone']);
        $this->assertSame('40', $data['analytics']['gid://shopify/Order/1001']['cost_of_goods_sold']);
        $this->assertSame([], $data['coverage']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['query'], 'OrderContributionAnalytics')
            && str_contains($request['variables']['query'], 'WHERE order_id IN (1001)')
            && str_contains($request['variables']['query'], 'SINCE 2026-10-01 UNTIL 2026-10-07')
            && ! str_contains($request['query'], 'unitCost'));
    }

    #[TestWith(['parse_error'])]
    #[TestWith(['missing_column'])]
    #[TestWith(['wrong_type'])]
    #[TestWith(['missing_amount'])]
    #[TestWith(['duplicate_order'])]
    #[TestWith(['unexpected_order'])]
    public function test_invalid_or_ambiguous_analytics_leave_historical_cost_unknown(string $failure): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $response = $this->analyticsResponse();
        $table = &$response['data']['shopifyqlQuery'];
        if ($failure === 'parse_error') {
            $table['parseErrors'] = ['Unavailable metric'];
        } elseif ($failure === 'missing_column') {
            array_pop($table['tableData']['columns']);
        } elseif ($failure === 'wrong_type') {
            $table['tableData']['columns'][1]['dataType'] = 'PERCENT';
        } elseif ($failure === 'missing_amount') {
            $table['tableData']['rows'][0]['cost_of_goods_sold'] = null;
        } elseif ($failure === 'duplicate_order') {
            $table['tableData']['rows'][] = $table['tableData']['rows'][0];
        } else {
            $table['tableData']['rows'][0]['order_id'] = '9999';
        }
        $this->fakeTransport($response);

        $data = app(ShopifyOrderContribution::class)->collect(Store::factory()->make(), '2026-10-01', '2026-10-02');

        $this->assertSame([], $data['analytics']);
        $this->assertCount(1, $data['orders']);
        $this->assertNotEmpty($data['coverage']);
    }

    public function test_missing_reports_scope_preserves_orders_without_attempting_analytics(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionShop/'))->once()->andReturn($this->shop([]));
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionOrders/'), Mockery::any())->once()->andReturn(['data' => ['orders' => ['edges' => [['node' => $this->order()]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionFees/'), Mockery::any())->once()->andThrow(new RuntimeException('secret-token'));

        $data = app(ShopifyOrderContribution::class)->collect(Store::factory()->make(), '2026-10-01', '2026-10-02');

        $this->assertSame([], $data['analytics']);
        $this->assertFalse($data['orders'][0]['fees_available']);
        $this->assertStringContainsString('read_reports', $data['coverage'][0]);
        $this->assertStringNotContainsString('secret-token', implode(' ', $data['coverage']));
    }

    public function test_older_order_access_and_scan_limit_are_explicit(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $this->fakeTransport($this->analyticsResponse(), true);

        $data = app(ShopifyOrderContribution::class)->collect(Store::factory()->make(), '2026-01-01', '2026-01-02');

        $this->assertTrue($data['truncated']);
        $this->assertStringContainsString('read_all_orders', $data['coverage'][0]);
    }

    public function test_shipstation_reads_paginated_labels_and_voids_by_store_and_order_without_ship_date_cutoff(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/shipments*' => function (Request $request) {
            $voids = isset($request['voidDateStart']);
            $page = (int) $request['page'];

            return Http::response(['shipments' => [['shipmentId' => $voids ? 3 : $page, 'orderId' => 77, 'voided' => $voids]], 'pages' => $voids ? 1 : 2]);
        }]);

        $labels = (new ShipStationClient('key', 'secret'))->getOrderCostShipments(77, 12, '2026-10-01', '2026-10-07');

        $this->assertSame([1, 2, 3], array_column($labels, 'shipmentId'));
        Http::assertSent(fn (Request $request): bool => (int) $request['orderId'] === 77 && (int) $request['storeId'] === 12
            && isset($request['voidDateStart']) && ! isset($request['shipDateStart']));
    }

    public function test_next_part_passes_the_cursor_and_requires_a_new_cursor_to_continue(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionShop/'))->andReturn($this->shop());
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionOrders/'), Mockery::on(fn (array $variables): bool => $variables['after'] === 'previous-order'))->once()->andReturn(['data' => ['orders' => ['edges' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next-order']]]]);

        $data = app(ShopifyOrderContribution::class)->collect(Store::factory()->make(), '2026-10-01', '2026-10-02', 'previous-order');

        $this->assertTrue($data['truncated']);
        $this->assertSame('next-order', $data['next_after']);
    }

    public function test_missing_shipstation_pagination_is_not_accepted_as_complete_cost_history(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => [['shipmentId' => 1]]])]);

        $this->expectException(UnexpectedResponse::class);
        (new ShipStationClient('key', 'secret'))->getOrderCostShipments(77, 12, '2026-10-01', '2026-10-07');
    }

    #[TestWith(['previous-order'])]
    #[TestWith([null])]
    #[TestWith([''])]
    public function test_nonadvancing_or_missing_cursor_is_rejected_when_more_orders_are_claimed(?string $cursor): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionShop/'))->andReturn($this->shop());
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionOrders/'), Mockery::any())->andReturn(['data' => ['orders' => ['edges' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => $cursor]]]]);

        $this->expectException(UnexpectedValueException::class);
        app(ShopifyOrderContribution::class)->collect(Store::factory()->make(), '2026-10-01', '2026-10-02', 'previous-order');
    }

    /** @param array<string, mixed> $analytics */
    private function fakeTransport(array $analytics, bool $truncated = false): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionShop/'))->andReturn($this->shop());
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionOrders/'), Mockery::any())->andReturn(['data' => ['orders' => ['edges' => [['node' => $this->order()]], 'pageInfo' => ['hasNextPage' => $truncated, 'endCursor' => $truncated ? 'next-order' : null]]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionFees/'), Mockery::any())->andReturn(['data' => ['order' => ['id' => 'gid://shopify/Order/1001', 'transactions' => []]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/OrderContributionAnalytics/'), Mockery::any())->andReturn($analytics);
    }

    /** @param list<string> $scopes
     * @return array<string, mixed> */
    private function shop(array $scopes = ['read_reports']): array
    {
        return ['data' => ['shop' => ['currencyCode' => 'USD', 'ianaTimezone' => 'Europe/Sofia'], 'currentAppInstallation' => ['accessScopes' => array_map(fn (string $scope): array => ['handle' => $scope], $scopes)]]];
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return ['id' => 'gid://shopify/Order/1001', 'legacyResourceId' => '1001', 'name' => '#WEB-1001', 'createdAt' => '2026-10-01T10:00:00Z'];
    }

    /** @return array<string, mixed> */
    private function analyticsResponse(): array
    {
        $row = ['order_id' => '1001', 'net_sales' => '100', 'shipping_charges' => '10', 'cost_of_goods_sold' => '40', 'net_sales_with_cost_recorded' => '100', 'net_sales_without_cost_recorded' => '0'];

        return ['data' => ['shopifyqlQuery' => ['parseErrors' => [], 'tableData' => ['columns' => array_map(fn (string $key): array => ['name' => $key, 'dataType' => $key === 'order_id' ? 'IDENTITY' : 'MONEY'], array_keys($row)), 'rows' => [$row]]]]];
    }
}
