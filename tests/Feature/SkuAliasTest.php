<?php

namespace Tests\Feature;

use App\Application\Reports\RunFulfilledItemsReport;
use App\Application\Reports\RunInventoryForecastReport;
use App\Application\Reports\RunReturnedItemsReport;
use App\Integrations\Shopify\Contracts\ShopifyCatalog;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Integrations\Shopify\Contracts\ShopifyPayments;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class SkuAliasTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_administrators_can_save_store_scoped_mappings_and_disable_them_without_deleting(): void
    {
        $this->get('/admin/sku-aliases')->assertRedirect(route('login'));
        [$operator] = $this->userWithStore(true);
        $this->actingAs($operator)->put('/admin/sku-aliases', ['enabled' => true, 'aliases' => $this->mappings()])->assertForbidden();
        [$admin, $store] = $this->admin();
        $other = Store::factory()->create();
        $this->actingAs($admin)->get('/admin/sku-aliases')->assertSeeText('Enable SKU aliases for new reports');
        $this->put('/admin/sku-aliases', ['enabled' => true, 'aliases' => [...$this->mappings(), ['alias' => '', 'master' => '']], 'store_id' => $other->id])->assertRedirect(route('admin.sku-aliases.edit'));
        $this->assertSame($this->mappings(), $store->fresh()->activeSkuAliases());
        $this->assertSame([], $other->fresh()->activeSkuAliases());
        $this->put('/admin/sku-aliases', ['enabled' => false, 'aliases' => $this->mappings()])->assertRedirect();
        $this->assertSame([], $store->fresh()->activeSkuAliases());
        $this->assertSame($this->mappings(), $store->fresh()->sku_aliases);
    }

    #[DataProvider('invalidMappings')]
    public function test_invalid_mappings_do_not_change_the_store(array $rows): void
    {
        [$admin, $store] = $this->admin();
        $this->actingAs($admin)->put('/admin/sku-aliases', ['enabled' => true, 'aliases' => $rows])->assertSessionHasErrors();
        $this->assertSame([], $store->fresh()->activeSkuAliases());
        $this->assertNull($store->fresh()->sku_aliases);
    }

    public static function invalidMappings(): array
    {
        return [
            'self' => [[['alias' => 'A', 'master' => 'A']]],
            'duplicate' => [[['alias' => 'A', 'master' => 'B'], ['alias' => 'A', 'master' => 'C']]],
            'chain' => [[['alias' => 'A', 'master' => 'B'], ['alias' => 'B', 'master' => 'C']]],
            'cycle' => [[['alias' => 'A', 'master' => 'B'], ['alias' => 'B', 'master' => 'A']]],
            'missing master' => [[['alias' => 'A', 'master' => '']]],
            'control character' => [[['alias' => "A\nB", 'master' => 'B']]],
            'length' => [[['alias' => str_repeat('A', 256), 'master' => 'B']]],
            'limit' => [array_fill(0, 201, ['alias' => 'A', 'master' => 'B'])],
            'numeric self' => [[['alias' => '0', 'master' => '0']]],
        ];
    }

    public function test_mappings_are_escaped_can_be_removed_and_switch_off_when_the_shop_changes(): void
    {
        [$admin, $store] = $this->admin();
        $rows = [['alias' => '<script>old</script>', 'master' => '0']];
        $store->update(['sku_aliases' => $rows, 'sku_aliases_enabled' => true]);
        $this->actingAs($admin)->get('/admin/sku-aliases')->assertDontSee('<script>old</script>', false)->assertSee('&lt;script&gt;old&lt;/script&gt;', false);
        $store->update(['shopify_store' => 'new-shop']);
        $this->assertSame([], $store->fresh()->activeSkuAliases());
        $this->put('/admin/sku-aliases', ['enabled' => false, 'aliases' => [['alias' => '', 'master' => '']]])->assertRedirect();
        $this->assertSame([], $store->fresh()->sku_aliases);
    }

    public function test_fulfilled_and_returned_reports_roll_up_explicit_codes_and_preserve_their_sources(): void
    {
        $store = Store::factory()->create(['sku_aliases' => $this->mappings(), 'sku_aliases_enabled' => true]);
        $items = [['title' => 'Old label', 'sku' => 'OLD', 'quantity' => 2], ['title' => 'New label', 'sku' => 'MASTER', 'quantity' => 3], ['title' => 'New label', 'sku' => 'UNRELATED', 'quantity' => 7]];
        $this->mock(ShopifyOrders::class)->shouldReceive('fulfilledItemCandidates')->once()->andReturn(ShopifyFixtures::history([['fulfillments' => [['status' => 'success', 'created_at' => '2026-10-20T00:00:00Z', 'line_items' => $items]]]]));
        $this->mock(ShopifyPayments::class)->shouldReceive('returnedItemCandidates')->once()->andReturn(ShopifyFixtures::history([['refunds' => [['created_at' => '2026-10-20T00:00:00Z', 'refund_line_items' => array_map(fn (array $item): array => ['quantity' => $item['quantity'], 'line_item' => $item], $items)]]]]));
        $fulfilled = app(RunFulfilledItemsReport::class)->handle($store, '2026-10-01', '2026-10-23')->rows;
        $returned = app(RunReturnedItemsReport::class)->handle($store, '2026-10-01', '2026-10-23')->rows;
        $expected = [['product' => 'MASTER', 'quantity' => 5, 'sku' => 'MASTER', 'source_skus' => ['MASTER', 'OLD']], ['product' => 'New label', 'quantity' => 7, 'sku' => 'UNRELATED', 'source_skus' => ['UNRELATED']]];
        $this->assertSame($expected, $fulfilled);
        $this->assertSame($expected, $returned);
    }

    public function test_disabled_mappings_keep_existing_report_grouping(): void
    {
        $store = Store::factory()->create(['sku_aliases' => $this->mappings()]);
        $this->mock(ShopifyOrders::class)->shouldReceive('fulfilledItemCandidates')->once()->andReturn(ShopifyFixtures::history([['fulfillments' => [['status' => 'success', 'created_at' => '2026-10-20T00:00:00Z', 'line_items' => [['title' => 'Legacy label', 'sku' => 'OLD', 'quantity' => 2]]]]]]));
        $this->assertSame([['product' => 'Legacy label', 'quantity' => 2]], app(RunFulfilledItemsReport::class)->handle($store, '2026-10-01', '2026-10-23')->rows);
    }

    public function test_forecast_uses_mapped_demand_against_one_master_stock(): void
    {
        $store = Store::factory()->create(['sku_aliases' => $this->mappings(), 'sku_aliases_enabled' => true]);
        $this->mock(ShopifyCatalog::class)->shouldReceive('inventoryForecastCandidates')->once()->andReturn(['products' => [['legacyResourceId' => '1', 'title' => 'Widget', 'variants' => [['sku' => 'MASTER', 'inventoryQuantity' => 10, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]]]]], 'orders' => [['line_items' => [['sku' => 'OLD', 'quantity' => 15], ['sku' => 'MASTER', 'quantity' => 15]]]], 'product_pages' => 1, 'order_pages' => 1, 'products_truncated' => false, 'orders_truncated' => false]);
        $result = app(RunInventoryForecastReport::class)->handle($store, '2026-09-23', '2026-10-23');
        $this->assertSame(30, $result->rows[0]['sold_30d']);
        $this->assertSame(['OLD', 'MASTER'], $result->rows[0]['source_skus']);
        $this->assertSame(10, $result->rows[0]['days_to_zero']);
        $this->assertSame([], $result->meta['aliasConflicts']);
    }

    public function test_exact_codes_include_numeric_zero_and_do_not_guess_from_names_or_case(): void
    {
        [$admin, $store] = $this->admin();
        $mappings = [['alias' => '0', 'master' => 'MASTER'], ['alias' => 'OLD', 'master' => 'MASTER']];
        $this->actingAs($admin)->put('/admin/sku-aliases', ['enabled' => true, 'aliases' => $mappings])->assertRedirect();
        $this->assertSame($mappings, $store->fresh()->activeSkuAliases());
        $items = [['sku' => '0', 'title' => '', 'quantity' => 1], ['sku' => 'OLD', 'title' => 'Same title', 'quantity' => 2], ['sku' => 'old', 'title' => 'Same title', 'quantity' => 4], ['sku' => '', 'title' => 'MASTER', 'quantity' => 8]];
        $this->mock(ShopifyOrders::class)->shouldReceive('fulfilledItemCandidates')->once()->andReturn(ShopifyFixtures::history([['fulfillments' => [['status' => 'success', 'created_at' => '2026-10-20T00:00:00Z', 'line_items' => $items]]]]));
        $rows = app(RunFulfilledItemsReport::class)->handle($store->fresh(), '2026-10-01', '2026-10-23')->rows;
        $this->assertCount(3, $rows);
        $this->assertSame([8, 3, 4], array_column($rows, 'quantity'));
        $this->assertSame(['0', 'OLD'], $rows[1]['source_skus']);
    }

    public function test_fulfilled_result_and_csv_keep_the_saved_alias_basis_after_configuration_changes(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['sku_aliases' => $this->mappings(), 'sku_aliases_enabled' => true]);
        $this->mock(ShopifyOrders::class)->shouldReceive('fulfilledItemCandidates')->once()->andReturn(ShopifyFixtures::history([['fulfillments' => [['status' => 'success', 'created_at' => '2026-10-20T00:00:00Z', 'line_items' => [['title' => 'Historical title', 'sku' => 'OLD', 'quantity' => 2]]]]]]));
        $dates = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23'];
        $this->actingAs($operator)->post('/reports/fulfilled-items', $dates)->assertSeeText('Explicit SKU aliases were applied.')->assertSeeText('MASTER')->assertSeeText('OLD');
        $run = $store->reportRuns()->where('tool', 'fulfilled_items')->sole();
        $store->update(['sku_aliases_enabled' => false]);
        $response = $this->post(route('reports.fulfilled-items.export'), [...$dates, 'report_run' => $run->id]);
        $this->assertStringContainsString('MASTER,2,MASTER,OLD', $response->streamedContent());
    }

    public function test_returned_result_and_csv_show_original_codes_safely(): void
    {
        [$operator] = $this->userWithStore(true, ['sku_aliases' => [['alias' => '=OLD', 'master' => '<b>MASTER</b>']], 'sku_aliases_enabled' => true]);
        $this->mock(ShopifyPayments::class)->shouldReceive('returnedItemCandidates')->once()->andReturn(ShopifyFixtures::history([['refunds' => [['created_at' => '2026-10-20T00:00:00Z', 'refund_line_items' => [['quantity' => 3, 'line_item' => ['name' => 'Old title', 'sku' => '=OLD']]]]]]]));
        $dates = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23'];
        $this->actingAs($operator)->post('/reports/returned-items', $dates)->assertSeeText('Explicit SKU aliases were applied.')->assertDontSee('<b>MASTER</b>', false)->assertSeeText('=OLD');
        $response = $this->post(route('reports.returned-items.export'), $dates);
        $this->assertStringContainsString("'=OLD", $response->streamedContent());
    }

    public function test_forecast_warns_when_alias_stock_would_duplicate_the_master(): void
    {
        $this->travelTo('2026-10-23');
        [$operator] = $this->userWithStore(true, ['sku_aliases' => $this->mappings(), 'sku_aliases_enabled' => true]);
        $variant = ['sku' => 'MASTER', 'inventoryQuantity' => 10, 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]];
        $this->mock(ShopifyCatalog::class)->shouldReceive('inventoryForecastCandidates')->once()->andReturn(['products' => [['legacyResourceId' => '1', 'variants' => [$variant, [...$variant, 'sku' => 'OLD']]]], 'orders' => [['line_items' => [['sku' => 'OLD', 'quantity' => 30]]]], 'product_pages' => 1, 'order_pages' => 1, 'products_truncated' => false, 'orders_truncated' => false]);
        $this->actingAs($operator)->post(route('reports.inventory-forecast.store'))->assertSeeText('Forecast excluded mapped SKUs with missing or ambiguous master inventory:')->assertSeeText('MASTER');
    }

    private function mappings(): array
    {
        return [['alias' => 'OLD', 'master' => 'MASTER']];
    }

    private function admin(): array
    {
        $user = User::factory()->admin()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
