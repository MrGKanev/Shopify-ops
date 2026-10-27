<?php

namespace Tests\Feature;

use App\Application\Reports\CaptureShipmentBacklog;
use App\Application\Reports\CaptureShopifyBacklog;
use App\Application\Reports\ShippingBillingCosts;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklogAndMarginIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_shipstation_backlog_snapshots_are_kept_per_store_and_never_reuse_another_stores_orders(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $first = Store::factory()->create(['store_number' => '42', 'shopify_timezone' => 'UTC']);
        $second = Store::factory()->create(['store_number' => '43', 'shopify_timezone' => 'UTC']);
        $empty = Store::factory()->create(['store_number' => '44', 'shopify_timezone' => 'UTC']);
        Http::fake(['ssapi.shipstation.com/orders*' => function (Request $request) {
            $storeId = (int) $request['storeId'];
            $orders = match ($storeId) {
                42 => $request['orderStatus'] === 'on_hold' ? [] : [$this->order(1, 42), $this->order(2, 42)],
                43 => $request['orderStatus'] === 'on_hold' ? [$this->order(3, 43)] : [],
                default => [],
            };

            return Http::response(['orders' => $orders, 'page' => 1, 'pages' => 1, 'total' => count($orders)]);
        }]);

        $one = app(CaptureShipmentBacklog::class)->handle($first);
        $two = app(CaptureShipmentBacklog::class)->handle($second);
        $none = app(CaptureShipmentBacklog::class)->handle($empty);

        $this->assertNotSame($one->id, $two->id);
        $this->assertSame([2, 0, 2], [$one->rows_found, $one->result['on_hold'], $one->result['awaiting']]);
        $this->assertSame([1, 1, 0], [$two->rows_found, $two->result['on_hold'], $two->result['awaiting']]);
        $this->assertSame(0, $none->rows_found);
        $this->assertSame([$one->id], $first->auditSnapshots()->where('tool', $one->tool)->pluck('id')->all());
        $this->assertSame([$two->id], $second->auditSnapshots()->where('tool', $one->tool)->pluck('id')->all());
        $this->assertSame($none->id, app(CaptureShipmentBacklog::class)->handle($empty)->id);
    }

    public function test_shopify_backlog_snapshots_are_kept_per_store_and_an_empty_store_stays_zero(): void
    {
        $this->travelTo('2026-10-24 12:00:00 UTC');
        $busy = Store::factory()->create(['shopify_timezone' => 'UTC']);
        $quiet = Store::factory()->create(['shopify_timezone' => 'UTC']);
        $this->mock(ShopifyOrders::class)->shouldReceive('shipmentBacklogCandidates')->twice()->andReturnUsing(fn (Store $store): array => [
            'fulfillment_orders' => $store->is($busy) ? [$this->fulfillment(1, 5)] : [],
            'scopes' => ['read_merchant_managed_fulfillment_orders', 'read_orders'], 'pages' => 1, 'truncated' => false,
        ]);

        $busySnapshot = app(CaptureShopifyBacklog::class)->handle($busy);
        $quietSnapshot = app(CaptureShopifyBacklog::class)->handle($quiet);

        $this->assertSame([1, 5], [$busySnapshot->rows_found, $busySnapshot->result['remaining_units']]);
        $this->assertSame([0, 0, []], [$quietSnapshot->rows_found, $quietSnapshot->result['remaining_units'], $quietSnapshot->result['currencies']]);
        $this->assertSame(1, $busy->auditSnapshots()->where('tool', 'shopify_backlog')->count());
        $this->assertSame(1, $quiet->auditSnapshots()->where('tool', 'shopify_backlog')->count());
        $this->assertSame($quietSnapshot->id, app(CaptureShopifyBacklog::class)->handle($quiet)->id);
    }

    public function test_imported_shipping_costs_never_cross_stores_even_with_the_same_shipment_id(): void
    {
        $mine = Store::factory()->create();
        $other = Store::factory()->create();
        ShippingBillingEntry::factory()->forAccount($mine)->create(['shipment_id' => '123', 'amount' => '10']);
        ShippingBillingEntry::factory()->forAccount($other)->create(['shipment_id' => '123', 'amount' => '99']);
        ShippingBillingEntry::factory()->forAccount($other)->create(['shipment_id' => '777', 'amount' => '5']);
        $costs = app(ShippingBillingCosts::class);

        $own = $costs->forShipments($mine, ['123', '777']);

        $this->assertSame(['123'], array_column($own['groups'], 'shipment_id'));
        $this->assertSame('10', rtrim(rtrim($own['groups'][0]['charges'], '0'), '.'));
        $this->assertSame([], $costs->forShipments(Store::factory()->create(), ['123', '777'])['groups']);
        $this->assertSame(['groups' => [], 'truncated' => false], $costs->forShipments($mine, []));
    }

    /** @return array<string, mixed> */
    private function order(int $id, int $storeId): array
    {
        return ['orderId' => $id, 'orderStatus' => $storeId === 43 ? 'on_hold' : 'awaiting_shipment', 'orderDate' => '2026-10-23T09:00:00', 'advancedOptions' => ['storeId' => $storeId]];
    }

    /** @return array<string, mixed> */
    private function fulfillment(int $id, int $remaining): array
    {
        return ['id' => 'gid://shopify/FulfillmentOrder/'.$id, 'status' => 'OPEN', 'order' => ['id' => 'gid://shopify/Order/'.$id, 'name' => '#'.$id, 'test' => false, 'cancelledAt' => null, 'presentmentCurrencyCode' => 'USD', 'taxesIncluded' => false], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrderLineItem/'.$id, 'remainingQuantity' => $remaining, 'totalQuantity' => $remaining, 'requiresShipping' => true, 'lineItem' => ['discountedUnitPriceAfterAllDiscountsSet' => ['presentmentMoney' => ['amount' => '2', 'currencyCode' => 'USD']]]]], 'pageInfo' => ['hasNextPage' => false]]];
    }
}
