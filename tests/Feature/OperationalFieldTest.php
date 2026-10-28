<?php

namespace Tests\Feature;

use App\Domain\Orders\OperationalFieldFilterMatcher;
use App\Domain\Orders\OperationalFieldResolver;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\OperationalField;
use App\Models\OperationalFieldFilter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\Support\ShopifyFixtures;
use Tests\TestCase;

class OperationalFieldTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resolver_distinguishes_present_missing_and_unreadable(): void
    {
        $resolver = new OperationalFieldResolver;
        $metafields = [
            ['namespace' => 'custom', 'key' => 'po', 'value' => ' PO-7 ', 'type' => 'single_line_text_field'],
            ['namespace' => 'custom', 'key' => 'blank', 'value' => '   ', 'type' => 'single_line_text_field'],
            ['namespace' => 'custom', 'key' => 'bad_json', 'value' => '{nope', 'type' => 'json'],
            ['namespace' => 'custom', 'key' => 'ok_json', 'value' => '{"a":1}', 'type' => 'json'],
            ['namespace' => 'custom', 'key' => 'array', 'value' => ['x'], 'type' => ''],
            ['namespace' => 'custom', 'key' => 'ctrl', 'value' => "a\x00b", 'type' => ''],
            ['namespace' => 'custom', 'key' => 'long', 'value' => str_repeat('x', 501), 'type' => ''],
        ];

        $this->assertSame(['state' => 'present', 'value' => 'PO-7'], $resolver->resolve('metafield', 'custom.po', $metafields, null));
        $this->assertSame('missing', $resolver->resolve('metafield', 'custom.blank', $metafields, null)['state']);
        $this->assertSame('missing', $resolver->resolve('metafield', 'custom.absent', $metafields, null)['state']);
        $this->assertSame('unreadable', $resolver->resolve('metafield', 'custom.bad_json', $metafields, null)['state']);
        $this->assertSame('present', $resolver->resolve('metafield', 'custom.ok_json', $metafields, null)['state']);
        $this->assertSame('unreadable', $resolver->resolve('metafield', 'custom.array', $metafields, null)['state']);
        $this->assertSame('unreadable', $resolver->resolve('metafield', 'custom.ctrl', $metafields, null)['state']);
        $this->assertSame('unreadable', $resolver->resolve('metafield', 'custom.long', $metafields, null)['state']);
        $this->assertSame('unreadable', $resolver->resolve('metafield', 'custom.po', null, null)['state']);

        $this->assertSame('present', $resolver->resolve('ss_custom', 'customField2', null, ['advancedOptions' => ['customField2' => 'DL-1']])['state']);
        $this->assertSame('missing', $resolver->resolve('ss_custom', 'customField1', null, ['advancedOptions' => ['customField2' => 'x']])['state']);
        $this->assertSame('unreadable', $resolver->resolve('ss_custom', 'customField1', null, ['items' => []])['state']);
    }

    public function test_field_management_is_store_scoped_and_validated(): void
    {
        $this->get(route('operational-fields.index'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('operational-fields.index'))->assertForbidden();

        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('operational-fields.store'), ['alias' => 'PO', 'source_type' => 'metafield', 'source' => 'no-dot'])->assertSessionHasErrors('source');
        $this->actingAs($operator)->post(route('operational-fields.store'), ['alias' => 'PO', 'source_type' => 'ss_custom', 'source' => 'customField4'])->assertSessionHasErrors('source');
        $this->actingAs($operator)->post(route('operational-fields.store'), ['alias' => 'PO', 'source_type' => 'metafield', 'source' => 'custom.po', 'required' => '1'])->assertRedirect(route('operational-fields.index'));
        $this->actingAs($operator)->post(route('operational-fields.store'), ['alias' => 'PO', 'source_type' => 'ss_custom', 'source' => 'customField1']);
        $field = $store->operationalFields()->sole();
        $this->assertSame('customField1', $field->source);
        $this->assertFalse($field->required);

        $foreign = OperationalField::factory()->create();
        $this->actingAs($operator)->delete(route('operational-fields.destroy', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->get(route('operational-fields.index'))->assertOk()->assertSee('PO');
        $this->actingAs($operator)->delete(route('operational-fields.destroy', $field->id))->assertRedirect();
        $this->assertSame(0, $store->operationalFields()->count());
        $this->assertSame(1, OperationalField::count());
    }

    public function test_worklist_findings_and_csv_use_the_same_names(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        OperationalField::factory()->for($store)->create(['alias' => 'PO reference', 'source' => 'custom.po', 'required' => true]);
        OperationalField::factory()->for($store)->customField(2)->create(['alias' => 'Deadline']);
        foreach (['1001', '1002', '1003'] as $number) {
            $store->printQueueItems()->create(['order_number' => $number]);
        }
        $ship = fn (string $number, array $advanced): array => ShipStationFixtures::order($number, ['advancedOptions' => ['storeId' => ShipStationFixtures::STORE_NUMBER] + $advanced, 'items' => [['sku' => '=SKU', 'name' => 'Tee', 'quantity' => 2]]]);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('findByOrderNumber')->with('1001', 42)->andReturn([$ship('1001', ['customField2' => '2026-11-01'])]);
        $client->shouldReceive('findByOrderNumber')->with('1002', 42)->andReturn([$ship('1002', [])]);
        $client->shouldReceive('findByOrderNumber')->with('1003', 42)->andReturn([]);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumbers')->andReturn(['1001' => [['id' => '11']], '1002' => [['id' => '12']]]);
        $shopify->shouldReceive('orderMetafields')->andReturn(['11' => [['namespace' => 'custom', 'key' => 'po', 'value' => '=HYPERLINK("x")', 'type' => 'single_line_text_field']], '12' => []]);
        $this->app->instance(ShopifyOrders::class, $shopify);

        $this->actingAs($operator)->get(route('operational-fields.index', ['generate' => 1]))->assertOk()->assertSeeText('Required values are not usable')->assertSeeText('1002 — PO reference: Value missing')->assertSeeText('2026-11-01')->assertSeeText('Not found in ShipStation:');

        $csv = $this->actingAs($operator)->get(route('operational-fields.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("Order,SKU,Item,Qty,Deadline,\"Deadline (status)\",\"PO reference\",\"PO reference (status)\"\r\n", $csv);
        $this->assertStringContainsString("1001,'=SKU,Tee,2,2026-11-01,present,\"'=HYPERLINK", $csv);
        $this->assertStringContainsString('1002,\'=SKU,Tee,2,,missing,,missing', $csv);
    }

    public function test_shopify_failure_marks_metafields_unreadable_not_missing(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        OperationalField::factory()->for($store)->create(['alias' => 'PO reference', 'source' => 'custom.po', 'required' => true]);
        $store->printQueueItems()->create(['order_number' => '1001']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('findByOrderNumber')->andReturn([ShipStationFixtures::order('1001', ['orderId' => 1])]);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumbers')->andThrow(new RuntimeException('secret'));
        $this->app->instance(ShopifyOrders::class, $shopify);

        $this->actingAs($operator)->get(route('operational-fields.index', ['generate' => 1]))->assertSeeText('1001 — PO reference: Value unreadable')->assertDontSeeText('secret');
        $this->actingAs($operator)->get(route('operational-fields.export'))->assertOk();
    }

    public function test_export_with_empty_queue_redirects_with_error(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('operational-fields.export'))->assertRedirect(route('operational-fields.index'))->assertSessionHasErrors('operational_fields');
    }

    public function test_matcher_keeps_missing_unreadable_and_present_apart(): void
    {
        $matcher = new OperationalFieldFilterMatcher;
        $row = ['fields' => [['alias' => 'PO', 'state' => 'present', 'value' => 'Rush-77'], ['alias' => 'Note', 'state' => 'unreadable', 'value' => ''], ['alias' => 'Date', 'state' => 'missing', 'value' => '']]];

        $this->assertTrue($matcher->matches($row, 'PO', 'present', null));
        $this->assertTrue($matcher->matches($row, 'PO', 'contains', 'rush'));
        $this->assertFalse($matcher->matches($row, 'PO', 'contains', ''));
        $this->assertFalse($matcher->matches($row, 'PO', 'missing', null));
        $this->assertTrue($matcher->matches($row, 'Note', 'unreadable', null));
        $this->assertFalse($matcher->matches($row, 'Note', 'missing', null));
        $this->assertFalse($matcher->matches($row, 'Note', 'contains', 'x'));
        $this->assertTrue($matcher->matches($row, 'Date', 'missing', null));
        $this->assertFalse($matcher->matches($row, 'Date', 'unreadable', null));
        $this->assertFalse($matcher->matches($row, 'Unknown', 'missing', null));
    }

    public function test_saved_filters_are_store_scoped_named_and_validated(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $field = OperationalField::factory()->for($store)->create(['alias' => 'PO']);
        $foreignField = OperationalField::factory()->create();

        $this->actingAs($operator)->post(route('operational-fields.filters.store'), ['name' => 'No PO', 'field' => $field->id, 'condition' => 'bogus'])->assertSessionHasErrors('condition');
        $this->actingAs($operator)->post(route('operational-fields.filters.store'), ['name' => 'Rush', 'field' => $field->id, 'condition' => 'contains'])->assertSessionHasErrors('text');
        $this->actingAs($operator)->post(route('operational-fields.filters.store'), ['name' => 'No PO', 'field' => $foreignField->id, 'condition' => 'missing'])->assertNotFound();
        $this->actingAs($operator)->post(route('operational-fields.filters.store'), ['name' => 'No PO', 'field' => $field->id, 'condition' => 'missing', 'text' => 'ignored'])->assertRedirect();
        $this->actingAs($operator)->post(route('operational-fields.filters.store'), ['name' => 'No PO', 'field' => $field->id, 'condition' => 'unreadable']);

        $filter = $store->operationalFieldFilters()->sole();
        $this->assertSame('unreadable', $filter->condition);
        $this->assertNull($filter->text);
        $this->actingAs($operator)->get(route('operational-fields.index'))->assertOk()->assertSee('No PO');

        $foreign = OperationalFieldFilter::factory()->create();
        $this->actingAs($operator)->get(route('operational-fields.index', ['filter' => $foreign->id]))->assertNotFound();
        $this->actingAs($operator)->delete(route('operational-fields.filters.destroy', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->delete(route('operational-fields.filters.destroy', $filter->id))->assertRedirect();
        $this->assertSame(0, $store->operationalFieldFilters()->count());
        $this->assertSame(1, OperationalFieldFilter::count());
    }

    public function test_saved_filter_limits_page_findings_and_csv_alike(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $po = OperationalField::factory()->for($store)->create(['alias' => 'PO reference', 'source' => 'custom.po', 'required' => true]);
        foreach (['1001', '1002', '1003'] as $number) {
            $store->printQueueItems()->create(['order_number' => $number]);
        }
        $ship = fn (string $number): array => ShipStationFixtures::order($number, ['items' => [['sku' => 'S'.$number, 'name' => 'Tee', 'quantity' => 1]]]);
        $client = ShipStationFixtures::bindClient();
        foreach (['1001', '1002', '1003'] as $number) {
            $client->shouldReceive('findByOrderNumber')->with($number, 42)->andReturn([$ship($number)]);
        }
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('findByOrderNumbers')->andReturn(['1001' => [['id' => '11']], '1002' => [['id' => '12']], '1003' => [['id' => '13']]]);
        $shopify->shouldReceive('orderMetafields')->andReturn([
            '11' => [['namespace' => 'custom', 'key' => 'po', 'value' => 'RUSH-1', 'type' => 'single_line_text_field']],
            '12' => [],
            '13' => [['namespace' => 'custom', 'key' => 'po', 'value' => '{nope', 'type' => 'json']],
        ]);
        $this->app->instance(ShopifyOrders::class, $shopify);
        $missing = OperationalFieldFilter::factory()->for($store)->create(['operational_field_id' => $po->id, 'name' => 'No PO', 'condition' => 'missing']);
        $rush = OperationalFieldFilter::factory()->for($store)->create(['operational_field_id' => $po->id, 'name' => 'Rush', 'condition' => 'contains', 'text' => 'rush']);

        $this->actingAs($operator)->get(route('operational-fields.index', ['generate' => 1, 'filter' => $missing->id]))->assertOk()
            ->assertSeeText('shows 1 of 3 loaded orders')->assertSeeText('1002 — PO reference: Value missing')->assertDontSeeText('RUSH-1');
        $csv = $this->actingAs($operator)->get(route('operational-fields.export', ['filter' => $missing->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('1002,S1002', $csv);
        $this->assertStringNotContainsString('1001,', $csv);
        $this->assertStringNotContainsString('1003,', $csv);

        $csv = $this->actingAs($operator)->get(route('operational-fields.export', ['filter' => $rush->id]))->streamedContent();
        $this->assertStringContainsString('1001,S1001,Tee,1,RUSH-1,present', $csv);
        $this->assertStringNotContainsString('1002,', $csv);

        $unreadable = OperationalFieldFilter::factory()->for($store)->create(['operational_field_id' => $po->id, 'name' => 'Broken', 'condition' => 'unreadable']);
        $csv = $this->actingAs($operator)->get(route('operational-fields.export', ['filter' => $unreadable->id]))->streamedContent();
        $this->assertStringContainsString('1003,S1003,Tee,1,,unreadable', $csv);
        $this->assertStringNotContainsString('1002,', $csv);
    }

    public function test_other_exports_can_append_operational_fields_with_missing_and_unreadable_apart(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        OperationalField::factory()->for($store)->create(['alias' => 'PO reference', 'source' => 'custom.po']);
        $ship = fn (string $number): array => ShipStationFixtures::order($number, ['orderId' => (int) ltrim($number, '#')]);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('findByOrderNumber')->with('#1001', 42)->andReturn([$ship('#1001')]);
        $client->shouldReceive('findByOrderNumber')->with('#1002', 42)->andReturn([$ship('#1002')]);
        $client->shouldReceive('findByOrderNumber')->with('#1003', 42)->andReturn([]);
        $shopify = Mockery::mock(ShopifyOrders::class);
        $shopify->shouldReceive('noTrackingCandidates')->andReturn(ShopifyFixtures::history(array_map(fn (string $name): array => ['name' => $name, 'created_at' => '2026-06-01', 'fulfillments' => [['created_at' => '2026-06-15T12:00:00Z', 'tracking_number' => '']]], ['#1001', '#1002', '#1003'])));
        $shopify->shouldReceive('findByOrderNumbers')->andReturn(['1001' => [['id' => '11']], '1002' => [['id' => '12']]]);
        $shopify->shouldReceive('orderMetafields')->andReturn(['11' => [['namespace' => 'custom', 'key' => 'po', 'value' => 'PO-9', 'type' => 'single_line_text_field']], '12' => []]);
        $this->app->instance(ShopifyOrders::class, $shopify);
        $input = ['start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'threshold' => 24];

        $plain = $this->actingAs($operator)->post(route('reports.no-tracking.export'), $input)->assertOk()->streamedContent();
        $this->assertStringContainsString("Order,Placed,\"Fulfillment date\",\"Hours since\",Carrier,Status,Email,Total\r\n", $plain);

        $csv = $this->actingAs($operator)->post(route('reports.no-tracking.export'), $input + ['operational_fields' => 1])->assertOk()->streamedContent();
        $this->assertStringContainsString('Total,"PO reference","PO reference (status)"', $csv);
        $this->assertMatchesRegularExpression('/^#1001,.*,PO-9,present\r?$/m', $csv);
        $this->assertMatchesRegularExpression('/^#1002,.*,,missing\r?$/m', $csv);
        $this->assertMatchesRegularExpression('/^#1003,.*,,unreadable\r?$/m', $csv);
    }
}
