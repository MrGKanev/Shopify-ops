<?php

namespace Tests\Feature;

use App\Domain\Reports\CustomsReadinessAnalyzer;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class CustomsReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ss_defaults_cover_missing_shopify_fields_and_national_hs_extension_is_compatible(): void
    {
        $variant = $this->variant();
        $variant['inventoryItem']['harmonizedSystemCode'] = null;
        $variant['inventoryItem']['countryCodeOfOrigin'] = null;

        $row = app(CustomsReadinessAnalyzer::class)->variant($variant, [$this->product()], true, 'USD');

        $this->assertSame([], $row['findings']);
        $this->assertSame('defaults_available', $row['status']);
        $this->assertSame('6109100012', $row['sources']['ShipStation default']['hs']);
        $this->assertSame([], app(CustomsReadinessAnalyzer::class)->variant($this->variant(), [$this->product()], true)['findings']);
    }

    public function test_nonphysical_items_are_excluded_and_invalid_values_stay_unknown(): void
    {
        $variant = $this->variant();
        $variant['inventoryItem']['requiresShipping'] = false;
        $this->assertNull(app(CustomsReadinessAnalyzer::class)->variant($variant, [], true));
        $variant['inventoryItem'] = ['requiresShipping' => true, 'harmonizedSystemCode' => 'fake', 'countryCodeOfOrigin' => 'ZZ'];
        $variant['price'] = 0;

        $row = app(CustomsReadinessAnalyzer::class)->variant($variant, [], true);

        $this->assertNull($row['sources']['Shopify']['value']);
        $this->assertContains('No usable HS code in the available sources.', $row['findings']);
        $this->assertContains('No usable origin country in the available sources.', $row['findings']);
        $this->assertContains('No positive item weight in the available sources.', $row['findings']);
    }

    #[TestWith(['hs', 'Shopify and ShipStation HS codes differ; review the intended override.'])]
    #[TestWith(['origin', 'Shopify and ShipStation origin countries differ; review the intended override.'])]
    #[TestWith(['weight', 'Shopify and ShipStation item weights differ.'])]
    #[TestWith(['duplicate', 'Duplicate SKU prevents choosing a ShipStation product default.'])]
    #[TestWith(['excluded', 'ShipStation excludes this product from customs; review the exemption.'])]
    #[TestWith(['coverage', 'ShipStation product coverage is incomplete; defaults cannot be confirmed.'])]
    public function test_conflicts_and_ambiguous_defaults_are_review_signals(string $case, string $message): void
    {
        $product = $this->product();
        $products = [$product];
        $complete = true;
        if ($case === 'hs') {
            $products[0]['customsTariffNo'] = '620910';
        } elseif ($case === 'origin') {
            $products[0]['customsCountryCode'] = 'US';
        } elseif ($case === 'weight') {
            $products[0]['weightOz'] = 10;
        } elseif ($case === 'duplicate') {
            $products[] = [...$product, 'productId' => 2];
        } elseif ($case === 'excluded') {
            $products[0]['noCustoms'] = true;
        } else {
            $complete = false;
        }

        $row = app(CustomsReadinessAnalyzer::class)->variant($this->variant(), $products, $complete);

        $this->assertSame('review', $row['status']);
        $this->assertSame([$message], $row['findings']);
    }

    public function test_prepared_declaration_supplies_fields_missing_from_defaults_without_claiming_item_mapping(): void
    {
        $row = app(CustomsReadinessAnalyzer::class)->variant(['id' => '1', 'sku' => 'A', 'title' => '', 'inventoryItem' => ['requiresShipping' => true]], [], true);
        $declaration = ['hs' => '610910', 'origin' => 'CN', 'description' => 'Cotton shirt', 'value' => 20.0];

        $rows = app(CustomsReadinessAnalyzer::class)->preparedCoverage([$row], [$declaration]);

        $this->assertNotContains('No usable HS code in the available sources.', $rows[0]['findings']);
        $this->assertContains('Prepared declarations supply fields missing from defaults; verify their item mapping.', $rows[0]['findings']);
    }

    public function test_multiline_declarations_are_validated_but_not_mapped_by_description(): void
    {
        $ss = $this->ssOrder();
        $ss['internationalOptions']['customsItems'][] = [...$ss['internationalOptions']['customsItems'][0], 'customsItemId' => 'd2', 'countryOfOrigin' => 'ZZ', 'value' => 0, 'quantity' => 0, 'harmonizedTariffCode' => '123', 'description' => ''];

        $result = app(CustomsReadinessAnalyzer::class)->shipment(['displayFulfillmentStatus' => 'UNFULFILLED'], [['pending_quantity' => 1], ['pending_quantity' => 1]], [], $ss, 'US');

        $this->assertContains('Customs rows cannot be reliably mapped to individual items; V1 has no guaranteed SKU link.', $result['findings']);
        $this->assertCount(5, $result['declarations'][1]['findings']);
        $this->assertContains('Customs quantity differs from pending Shopify items; review split or partial shipments.', $result['findings']);
    }

    public function test_same_country_does_not_raise_missing_customs_declaration_and_unknown_items_do_not_raise_quantity_mismatch(): void
    {
        $ss = $this->ssOrder();
        $ss['shipTo']['country'] = 'US';
        unset($ss['internationalOptions']);

        $result = app(CustomsReadinessAnalyzer::class)->shipment([], [['pending_quantity' => 1]], [], $ss, 'US');

        $this->assertNotContains('No prepared customs rows. Review whether this route requires a declaration.', $result['findings']);
        $unknown = app(CustomsReadinessAnalyzer::class)->shipment([], [], [], $this->ssOrder(), 'US', false);
        $this->assertNotContains('Customs quantity differs from pending Shopify items; review split or partial shipments.', $unknown['findings']);
    }

    public function test_pending_item_matching_uses_line_identity_and_quantity_not_only_count(): void
    {
        $analyzer = app(CustomsReadinessAnalyzer::class);
        $lines = [['id' => 'gid://shopify/LineItem/11', 'pending_quantity' => 1]];

        $this->assertTrue($analyzer->matchesPendingItems($lines, [['lineItemKey' => '11', 'quantity' => 1]]));
        $this->assertFalse($analyzer->matchesPendingItems($lines, [['lineItemKey' => '22', 'quantity' => 1]]));
        $this->assertFalse($analyzer->matchesPendingItems($lines, [['lineItemKey' => '11', 'quantity' => 2]]));
        $this->assertFalse($analyzer->matchesPendingItems($lines, [['sku' => 'A', 'quantity' => 1]]));
    }

    public function test_order_screen_reads_selected_store_and_does_not_change_orders_or_declarations(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->fakeOrderSources($store);

        $response = $this->actingAs($operator)->post(route('orders.customs.check'), ['order_number' => '#1001', 'store_id' => 999]);

        $response->assertSeeText('Prepared ShipStation customs rows')->assertSeeText('6109100012')->assertSeeText('Cotton shirt')->assertSeeText('US')->assertSeeText('CA');
        $this->assertDatabaseCount('push_logs', 0);
        $this->assertDatabaseCount('operational_issues', 0);
        Http::assertNothingSent();
    }

    public function test_catalog_customs_is_opt_in_queued_and_uses_shared_component_with_safe_text(): void
    {
        $this->freezeTime();
        [$operator] = $this->userWithStore(true);
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsCatalogProducts/'), ['after' => null])->andReturn(['data' => ['products' => ['nodes' => [['id' => 'gid://shopify/Product/1', 'legacyResourceId' => '1', 'title' => '<script>x</script>', 'onlineStoreUrl' => 'https://example.test', 'seo' => ['title' => 'x', 'description' => 'x'], 'collections' => ['nodes' => [['id' => 'c1']]]]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        $transport->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsProductVariants/'), Mockery::any())->andReturn(['data' => ['product' => ['id' => 'gid://shopify/Product/1', 'updatedAt' => '2026-10-07T00:00:00Z', 'variants' => ['nodes' => [$this->variant()], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]);
        $ss = Mockery::mock(ShipStationClientContract::class);
        $ss->shouldReceive('customsProducts')->once()->andReturn(['products' => [$this->product()], 'pages' => 1, 'truncated' => false]);
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->andReturn($ss);

        $this->actingAs($operator)->post(route('reports.catalog-quality.store'), ['include_customs' => 1])
            ->assertSeeText('Customs readiness')->assertSeeText('Default fields available')->assertDontSee('<script>x</script>', false)->assertSee('&lt;script&gt;x&lt;/script&gt;', false);
    }

    public function test_customs_endpoint_requires_permission_and_valid_order_number(): void
    {
        $this->post(route('orders.customs.check'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->post(route('orders.customs.check'), ['order_number' => '1001'])->assertForbidden();
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('orders.customs.check'), ['order_number' => ['bad']])->assertSessionHasErrors('order_number');
    }

    public function test_foreign_shipstation_order_is_not_used_for_customs_or_warehouse_data(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->fakeOrderSources($store, true);

        $this->actingAs($operator)->post(route('orders.customs.check'), ['order_number' => '1001'])
            ->assertSeeText('No imported ShipStation order')
            ->assertDontSeeText('Prepared ShipStation customs rows');
    }

    public function test_customs_source_failure_is_safe_and_does_not_write(): void
    {
        [$operator] = $this->userWithStore(true);
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->andThrow(new RuntimeException('secret-token'));

        $this->actingAs($operator)->from(route('orders.push.create'))->post(route('orders.customs.check'), ['order_number' => '1001'])
            ->assertRedirect(route('orders.push.create'))
            ->assertSessionHasErrors('order_number');
        $this->assertStringNotContainsString('secret-token', session('errors')->first('order_number'));
        $this->assertDatabaseCount('push_logs', 0);
    }

    /** @return array<string, mixed> */
    private function variant(): array
    {
        return ['id' => 'gid://shopify/ProductVariant/1', 'title' => 'Shirt', 'sku' => 'A', 'price' => '20', 'inventoryItem' => ['requiresShipping' => true, 'harmonizedSystemCode' => '610910', 'countryCodeOfOrigin' => 'CN', 'duplicateSkuCount' => 0, 'measurement' => ['weight' => ['value' => 1, 'unit' => 'OUNCES']]]];
    }

    /** @return array<string, mixed> */
    private function product(): array
    {
        return ['productId' => 1, 'active' => true, 'sku' => 'A', 'customsTariffNo' => '6109.10.0012', 'customsCountryCode' => 'CN', 'customsDescription' => 'Cotton shirt', 'customsValue' => 20, 'weightOz' => 1, 'noCustoms' => false];
    }

    /** @return array<string, mixed> */
    private function ssOrder(): array
    {
        return ['orderId' => 77, 'orderNumber' => '1001', 'orderStatus' => 'awaiting_shipment', 'advancedOptions' => ['storeId' => 12, 'warehouseId' => 5], 'shipTo' => ['country' => 'CA'], 'weight' => ['value' => 2, 'units' => 'ounces'], 'items' => [['lineItemKey' => '11', 'quantity' => 1]], 'internationalOptions' => ['customsItems' => [['customsItemId' => 'd1', 'description' => 'Cotton shirt', 'quantity' => 1, 'value' => 20, 'harmonizedTariffCode' => '6109100012', 'countryOfOrigin' => 'CN']]]];
    }

    private function fakeOrderSources(Store $store, bool $foreignStore = false): void
    {
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)), '1001')->andReturn([['id' => '1001', 'name' => '#1001']]);
        $this->mock(ShopifyTransport::class)->shouldReceive('graphql')->with(Mockery::any(), Mockery::pattern('/CustomsOrderLines/'), ['id' => 'gid://shopify/Order/1001', 'after' => null])->andReturn(['data' => ['order' => ['id' => 'gid://shopify/Order/1001', 'name' => '#1001', 'updatedAt' => '2026-10-07T00:00:00Z', 'cancelledAt' => null, 'displayFulfillmentStatus' => 'UNFULFILLED', 'shippingAddress' => ['countryCodeV2' => 'CA'], 'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/11', 'title' => 'Shirt', 'sku' => 'A', 'currentQuantity' => 1, 'unfulfilledQuantity' => 1, 'requiresShipping' => true, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '20', 'currencyCode' => 'USD']], 'variant' => $this->variant()]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]);
        $ss = Mockery::mock(ShipStationClientContract::class);
        $candidate = $this->ssOrder();
        if ($foreignStore) {
            $candidate['advancedOptions']['storeId'] = 13;
        }
        $ss->shouldReceive('findByOrderNumber')->once()->with('1001', 12)->andReturn([$candidate]);
        if (! $foreignStore) {
            $ss->shouldReceive('getOrder')->once()->with(77)->andReturn($this->ssOrder());
            $ss->shouldReceive('customsProducts')->once()->andReturn(['products' => [$this->product()], 'pages' => 1, 'truncated' => false]);
            $ss->shouldReceive('getWarehouse')->once()->with(5)->andReturn(['warehouseId' => 5, 'originAddress' => ['country' => 'US']]);
        } else {
            $ss->shouldNotReceive('getOrder');
            $ss->shouldNotReceive('customsProducts');
            $ss->shouldNotReceive('getWarehouse');
        }
        $this->mock(ShipStationClientFactory::class)->shouldReceive('forStore')->andReturn($ss);
    }
}
