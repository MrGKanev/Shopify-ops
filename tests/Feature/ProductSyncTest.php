<?php

namespace Tests\Feature;

use App\Domain\Reports\CustomsReadinessAnalyzer;
use App\Domain\Reports\PackageWeightAnalyzer;
use App\Domain\Reports\ProductSyncAnalyzer;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Integrations\Shopify\ShopifyCustoms;
use App\Integrations\Shopify\ShopifyProductSync;
use App\Models\Store;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use UnexpectedValueException;

class ProductSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_form_is_manual_and_does_not_fetch_external_data(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('reports.product-sync'))->assertOk()->assertSeeText('Product Sync & Package Weight');

        Http::assertNothingSent();
        $this->assertDatabaseCount('report_runs', 0);
    }

    #[TestWith(['shipment', 'order_number', ['order_number' => '']])]
    #[TestWith(['shipment', 'shipment_id', ['shipment_id' => -1]])]
    #[TestWith(['shipment', 'tare', ['tare' => -1]])]
    #[TestWith(['shipment', 'tare_unit', ['tare_unit' => 'stones']])]
    #[TestWith(['shipment', 'dim_divisor', ['dim_divisor' => 0]])]
    #[TestWith(['shipment', 'dim_basis', ['dim_basis' => 'unknown']])]
    #[TestWith(['invalid', 'mode', []])]
    public function test_invalid_inputs_do_not_start_a_report(string $mode, string $field, array $changes): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->post(route('reports.product-sync.store'), [...$this->input(), 'mode' => $mode, ...$changes])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('report_runs', 0);
        Http::assertNothingSent();
    }

    public function test_incomplete_credentials_prevent_scanning(): void
    {
        [$operator] = $this->userWithStore(true, ['shipstation_api_secret' => '']);

        $this->actingAs($operator)->post(route('reports.product-sync.store'), $this->input())->assertSeeText('Shopify and ShipStation credentials are required');

        $this->assertDatabaseCount('report_runs', 0);
        Http::assertNothingSent();
    }

    public function test_catalog_finds_unpaired_skus_duplicates_weights_and_customs_differences(): void
    {
        $variant = $this->variant();
        $variant['product'] = ['title' => 'Widget'];
        $second = [...$variant, 'id' => 'gid://shopify/ProductVariant/2', 'sku' => 'OTHER'];
        $duplicate = [...$variant, 'id' => 'gid://shopify/ProductVariant/3'];
        $ssOnly = [...$this->product(), 'productId' => 2, 'sku' => 'SS-ONLY'];

        $rows = app(ProductSyncAnalyzer::class)->catalog([$variant, $second, $duplicate], [$this->product(), $ssOnly], true, true);

        $this->assertCount(4, $rows);
        $this->assertSame(2, $rows[0]['shopify_count']);
        $this->assertContains('Duplicate SKU prevents choosing a ShipStation product default.', $rows[0]['findings']);
        $this->assertContains('SKU has no active ShipStation product counterpart.', $rows[1]['findings']);
        $this->assertContains('Account-wide ShipStation SKU has no Shopify counterpart in this store.', $rows[3]['findings']);
        $single = app(ProductSyncAnalyzer::class)->catalog([$variant], [$this->product()], true, true)[0];
        $this->assertContains('Shopify and ShipStation item weights differ.', $single['findings']);
        $this->assertContains('Shopify and ShipStation HS codes differ; review the intended override.', $single['findings']);
        $this->assertContains('Shopify and ShipStation origin countries differ; review the intended override.', $single['findings']);
    }

    public function test_incomplete_catalogs_do_not_claim_definitive_missing_counterparts(): void
    {
        $rows = app(ProductSyncAnalyzer::class)->catalog([$this->variant()], [[...$this->product(), 'sku' => 'OTHER']], false, false);

        $this->assertContains('SKU was not found in the incomplete ShipStation scan.', $rows[0]['findings']);
        $this->assertContains('ShipStation SKU was not found in the incomplete Shopify scan.', $rows[1]['findings']);
    }

    public function test_shipstation_duplicate_candidates_remain_visible_without_choosing_one(): void
    {
        $rows = app(ProductSyncAnalyzer::class)->catalog([$this->variant()], [$this->product(), [...$this->product(), 'productId' => 2, 'weightOz' => 20]], true, true);

        $this->assertSame(2, $rows[0]['ss_count']);
        $this->assertNull($rows[0]['sources']['ShipStation default']['grams']);
        $this->assertArrayHasKey('ShipStation candidate #1', $rows[0]['sources']);
        $this->assertArrayHasKey('ShipStation candidate #2', $rows[0]['sources']);
    }

    public function test_selected_partial_shipment_uses_only_its_quantities_and_keeps_sources_separate(): void
    {
        $analysis = $this->analyze();

        $this->assertSame(500.0, $analysis['summary']['comparisons']['Shopify current']['goods_grams']);
        $this->assertSame(400.0, $analysis['summary']['comparisons']['Imported SS item']['goods_grams']);
        $this->assertSame(450.0, $analysis['summary']['comparisons']['Shipment item snapshot']['goods_grams']);
        $this->assertSame(650.0, $analysis['summary']['comparisons']['Shopify current']['package_grams']);
        $this->assertSame(-50.0, $analysis['summary']['comparisons']['Shopify current']['delta_grams']);
        $this->assertNull($analysis['summary']['measured_grams']);
        $this->assertNull($analysis['summary']['carrier_adjustment']);
        $this->assertSame(1, $analysis['rows'][0]['quantity']);
    }

    public function test_unknown_tare_does_not_become_zero(): void
    {
        $analysis = $this->analyze(input: ['mode' => 'shipment', 'tare_unit' => 'grams', 'dim_basis' => 'cm_kg']);

        $this->assertNull($analysis['summary']['tare_grams']);
        $this->assertSame(500.0, $analysis['summary']['comparisons']['Shopify current']['goods_grams']);
        $this->assertNull($analysis['summary']['comparisons']['Shopify current']['package_grams']);
        $this->assertNull($analysis['summary']['comparisons']['Shopify current']['delta_grams']);
    }

    public function test_explicit_zero_tare_and_unit_conversion_are_supported(): void
    {
        $analysis = $this->analyze(input: [...$this->input(), 'tare' => 0, 'tare_unit' => 'pounds']);

        $this->assertSame(0.0, $analysis['summary']['tare_grams']);
        $this->assertSame(500.0, $analysis['summary']['comparisons']['Shopify current']['package_grams']);
        $weights = app(CustomsReadinessAnalyzer::class);
        $this->assertSame(1000.0, $weights->grams(1, 'KILOGRAMS'));
        $this->assertSame(453.59237, $weights->grams(1, 'pounds'));
        $this->assertNull($weights->grams('1e308', 'kilograms'));
    }

    public function test_missing_snapshot_weight_is_not_replaced_by_current_defaults(): void
    {
        $shipment = $this->shipment();
        $shipment['shipmentItems'][0]['weight'] = null;
        $analysis = $this->analyze(shipment: $shipment);

        $this->assertNull($analysis['summary']['comparisons']['Shipment item snapshot']['goods_grams']);
        $this->assertSame(400.0, $analysis['summary']['comparisons']['Imported SS item']['goods_grams']);
    }

    #[TestWith(['quantity'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['identity'])]
    #[TestWith(['unknown_unit'])]
    public function test_unsafe_mapping_or_unknown_units_prevent_confirmed_totals(string $case): void
    {
        $shipment = $this->shipment();
        if ($case === 'quantity') {
            $shipment['shipmentItems'][0]['quantity'] = 3;
        } elseif ($case === 'duplicate') {
            $shipment['shipmentItems'][] = $shipment['shipmentItems'][0];
        } elseif ($case === 'identity') {
            $shipment['shipmentItems'][0]['orderItemId'] = 999;
        } else {
            $shipment['shipmentItems'][0]['weight']['units'] = 'stones';
        }
        $analysis = $this->analyze(shipment: $shipment);

        $this->assertNull($analysis['summary']['comparisons']['Shipment item snapshot']['goods_grams']);
    }

    public function test_bundle_weight_uses_component_quantities_and_never_adds_parent_weight(): void
    {
        $variant = $this->variant();
        $variant['requiresComponents'] = true;
        $variant['productVariantComponents'] = ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['quantity' => 2, 'productVariant' => $this->variant()]]];
        $analyzer = app(PackageWeightAnalyzer::class);

        $this->assertSame(1000.0, $analyzer->variantGrams($variant));
        $variant['productVariantComponents']['pageInfo']['hasNextPage'] = true;
        $this->assertNull($analyzer->variantGrams($variant));
        $variant['productVariantComponents']['pageInfo']['hasNextPage'] = false;
        $variant['productVariantComponents']['nodes'][0]['productVariant']['requiresComponents'] = true;
        $this->assertNull($analyzer->variantGrams($variant));
    }

    public function test_dim_weight_is_a_separate_user_divisor_scenario(): void
    {
        $shipment = $this->shipment();
        $shipment['dimensions'] = ['length' => 20, 'width' => 10, 'height' => 10, 'units' => 'centimeters'];
        $analysis = $this->analyze(shipment: $shipment, input: [...$this->input(), 'dim_divisor' => 5000]);

        $this->assertSame(400.0, $analysis['summary']['dim_grams']);
        $this->assertSame(600.0, $analysis['summary']['billable_scenario_grams']);
        $this->assertNull($analysis['summary']['carrier_adjustment']);
        $shipment['dimensions']['units'] = 'unknown';
        $this->assertNull($this->analyze(shipment: $shipment, input: [...$this->input(), 'dim_divisor' => 5000])['summary']['dim_grams']);
    }

    public function test_shopify_catalog_rejects_repeated_cursors(): void
    {
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->twice()->andReturn(['data' => ['productVariants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'same']]]]);

        $this->expectException(UnexpectedValueException::class);
        app(ShopifyProductSync::class)->catalog(Store::factory()->make());
    }

    public function test_catalog_request_records_a_result_and_escapes_remote_text(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $this->mock(ShopifyProductSync::class)->shouldReceive('catalog')->once()->withArgs(fn (Store $selected): bool => $selected->is($store))->andReturn(['variants' => [[...$this->variant(), 'title' => '<script>alert(1)</script>']], 'pages' => 1, 'truncated' => false]);
        Http::fake(['https://ssapi.shipstation.com/products*' => Http::response(['products' => [$this->product()], 'pages' => 1])]);

        $this->actingAs($operator)->post(route('reports.product-sync.store'), [...$this->input(), 'mode' => 'catalog'])->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);

        $this->assertDatabaseHas('report_runs', ['store_id' => $store->id, 'tool' => 'product_sync', 'status' => 'completed']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_shipment_request_uses_current_store_and_the_selected_package_only(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->mockOrder($store);
        Http::fake([
            'https://ssapi.shipstation.com/products*' => Http::response(['products' => [$this->product()], 'pages' => 1]),
            'https://ssapi.shipstation.com/orders*' => fn (Request $request) => Http::response(str_contains($request->url(), '/77') ? $this->order() : ['orders' => [$this->order()], 'pages' => 1]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => [$this->shipment(), [...$this->shipment(), 'shipmentId' => 89, 'orderId' => 999]], 'pages' => 1]),
        ]);
        $this->mock(ShopifyCustoms::class)->shouldReceive('productSyncOrder')->once()->withArgs(fn (Store $selected, string $id): bool => $selected->is($store) && $id === 'gid://shopify/Order/1')->andReturn(['order' => [], 'lines' => [$this->line()], 'truncated' => false]);

        $response = $this->actingAs($operator)->post(route('reports.product-sync.store'), $this->input());

        $response->assertOk()->assertSeeText('Shipment #88')->assertSeeText('650.00')->assertSeeText('Declared shipment weight')->assertDontSeeText('Shipment #89');
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_foreign_shipment_is_rejected_without_loading_shopify_item_data(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->mockOrder($store);
        $this->mock(ShopifyCustoms::class)->shouldNotReceive('productSyncOrder');
        Http::fake([
            'https://ssapi.shipstation.com/products*' => Http::response(['products' => [$this->product()], 'pages' => 1]),
            'https://ssapi.shipstation.com/orders*' => fn (Request $request) => Http::response(str_contains($request->url(), '/77') ? $this->order() : ['orders' => [$this->order()], 'pages' => 1]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => [[...$this->shipment(), 'orderId' => 999]], 'pages' => 1]),
        ]);

        $this->actingAs($operator)->post(route('reports.product-sync.store'), $this->input())->assertSeeText('could not be completed');

        $this->assertDatabaseHas('report_runs', ['store_id' => $store->id, 'status' => 'failed']);
    }

    public function test_shipment_selection_does_not_assume_the_entire_order_is_one_package(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '12']);
        $this->mockOrder($store);
        $this->mock(ShopifyCustoms::class)->shouldNotReceive('productSyncOrder');
        Http::fake([
            'https://ssapi.shipstation.com/products*' => Http::response(['products' => [$this->product()], 'pages' => 1]),
            'https://ssapi.shipstation.com/orders*' => fn (Request $request) => Http::response(str_contains($request->url(), '/77') ? $this->order() : ['orders' => [$this->order()], 'pages' => 1]),
            'https://ssapi.shipstation.com/shipments*' => Http::response(['shipments' => [$this->shipment(), [...$this->shipment(), 'shipmentId' => 89, 'isReturnLabel' => true]], 'pages' => 1]),
        ]);

        $this->actingAs($operator)->post(route('reports.product-sync.store'), [...$this->input(), 'shipment_id' => null])->assertOk()->assertSeeText('Choose a shipment from this order')->assertSeeText('#88')->assertDontSeeText('#89')->assertDontSeeText('Declared minus expected');
    }

    public function test_shopify_catalog_collects_pages_and_detects_duplicate_identities(): void
    {
        $store = Store::factory()->make();
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->once()->withArgs(fn (Store $selected, string $query, array $variables): bool => $selected === $store && $variables === ['after' => null])->andReturn(['data' => ['productVariants' => ['nodes' => [$this->variant()], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next']]]]);
        $transport->shouldReceive('graphql')->once()->withArgs(fn (Store $selected, string $query, array $variables): bool => $variables === ['after' => 'next'])->andReturn(['data' => ['productVariants' => ['nodes' => [[...$this->variant(), 'id' => 'gid://shopify/ProductVariant/2']], 'pageInfo' => ['hasNextPage' => false]]]]);

        $result = app(ShopifyProductSync::class)->catalog($store);

        $this->assertCount(2, $result['variants']);
        $this->assertSame(2, $result['pages']);
        $this->assertFalse($result['truncated']);
    }

    public function test_product_sync_bundle_changes_are_rejected(): void
    {
        $variant = [...$this->variant(), 'requiresComponents' => true, 'updatedAt' => '2026-10-08T00:00:00Z'];
        $transport = $this->mock(ShopifyTransport::class);
        $transport->shouldReceive('graphql')->once()->andReturn(['data' => ['productVariant' => ['id' => $variant['id'], 'updatedAt' => '2026-10-08T01:00:00Z']]]);

        $this->expectException(UnexpectedValueException::class);
        app(ShopifyProductSync::class)->withBundle(Store::factory()->make(), $variant);
    }

    public function test_incomplete_lines_and_merged_orders_leave_totals_unknown(): void
    {
        $analyzer = app(PackageWeightAnalyzer::class);

        $incomplete = $analyzer->analyze([$this->line()], $this->order(), $this->shipment(), [$this->product()], $this->input(), false);
        $this->assertNull($incomplete['summary']['comparisons']['Shopify current']['goods_grams']);
        $order = $this->order();
        $order['advancedOptions']['mergedOrSplit'] = true;
        $merged = $analyzer->analyze([$this->line()], $order, $this->shipment(), [$this->product()], $this->input(), true);
        $this->assertSame([], $merged['rows']);
        $this->assertNull($merged['summary']['comparisons']['Shipment item snapshot']['goods_grams']);
    }

    public function test_dim_inch_units_are_converted_to_the_selected_metric_basis(): void
    {
        $shipment = $this->shipment();
        $shipment['dimensions'] = ['length' => 10, 'width' => 10, 'height' => 10, 'units' => 'inches'];

        $result = $this->analyze(shipment: $shipment, input: [...$this->input(), 'dim_divisor' => 5000]);

        $this->assertEqualsWithDelta(3277.4128, $result['summary']['dim_grams'], 0.00001);
    }

    public function test_product_sync_form_is_localized_and_displays_validation_errors(): void
    {
        [$operator] = $this->userWithStore(true);
        app()->setLocale('bg');

        $this->actingAs($operator)->get(route('reports.product-sync'))->assertOk()->assertSeeText('Продуктова синхронизация и тегло на пакет')->assertSeeText('Тара на пакета');
        $this->from(route('reports.product-sync'))->followingRedirects()->post(route('reports.product-sync.store'), [...$this->input(), 'tare' => -1])->assertOk()->assertSeeText('Тарата трябва да е нула или положителна стойност.');
    }

    private function mockOrder(Store $store): void
    {
        $this->mock(ShopifyOrders::class)->shouldReceive('findByOrderNumber')->once()->withArgs(fn (Store $selected, string $number): bool => $selected->is($store) && $number === '1001')->andReturn([['id' => 1, 'name' => '#1001']]);
    }

    /** @return array<string, mixed> */
    private function analyze(?array $shipment = null, ?array $input = null): array
    {
        return app(PackageWeightAnalyzer::class)->analyze([$this->line()], $this->order(), $shipment ?? $this->shipment(), [$this->product()], $input ?? $this->input(), true);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['mode' => 'shipment', 'order_number' => '1001', 'shipment_id' => 88, 'tare' => 150, 'tare_unit' => 'grams', 'dim_basis' => 'cm_kg'];
    }

    /** @return array<string, mixed> */
    private function variant(): array
    {
        return ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU1', 'title' => 'Widget', 'requiresComponents' => false, 'inventoryItem' => ['requiresShipping' => true, 'harmonizedSystemCode' => '123456', 'countryCodeOfOrigin' => 'US', 'duplicateSkuCount' => 0, 'measurement' => ['weight' => ['value' => 500, 'unit' => 'GRAMS']]]];
    }

    /** @return array<string, mixed> */
    private function product(): array
    {
        return ['productId' => 1, 'sku' => 'SKU1', 'name' => 'Widget', 'active' => true, 'weightOz' => 10, 'customsDescription' => 'Customs widget', 'customsTariffNo' => '654321', 'customsCountryCode' => 'CA', 'customsValue' => 10];
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        return ['id' => 'gid://shopify/LineItem/21', 'sku' => 'SKU1', 'title' => 'Widget', 'currentQuantity' => 2, 'requiresShipping' => true, 'variant' => $this->variant()];
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return ['orderId' => 77, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 12], 'items' => [['orderItemId' => 55, 'lineItemKey' => '21', 'sku' => 'SKU1', 'quantity' => 2, 'weight' => ['value' => 400, 'units' => 'grams']]]];
    }

    /** @return array<string, mixed> */
    private function shipment(): array
    {
        return ['shipmentId' => 88, 'orderId' => 77, 'voided' => false, 'isReturnLabel' => false, 'trackingNumber' => 'TRACK88', 'weight' => ['value' => 600, 'units' => 'grams'], 'shipmentItems' => [['orderItemId' => 55, 'sku' => 'SKU1', 'quantity' => 1, 'weight' => ['value' => 450, 'units' => 'grams']]]];
    }
}
