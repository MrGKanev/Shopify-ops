<?php

namespace Tests\Feature;

use App\Domain\Orders\PickListBuilder;
use App\Models\PickListSnapshot;
use App\Models\PickListTemplate;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class PickListTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_builder_combines_by_sku_and_variant_with_allocations_and_natural_bin_order(): void
    {
        $orders = [
            ['orderNumber' => '1001', 'items' => [
                ['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 2, 'warehouseLocation' => 'A10', 'options' => [['name' => 'Size', 'value' => 'M']]],
                ['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 1, 'warehouseLocation' => 'A10', 'options' => [['name' => 'Size', 'value' => 'L']]],
                ['sku' => 'MUG', 'name' => 'Mug', 'quantity' => 1, 'warehouseLocation' => 'A2'],
            ]],
            ['orderNumber' => '1002', 'items' => [
                ['sku' => 'tee', 'name' => 'Tee', 'quantity' => 3, 'options' => [['name' => 'Size', 'value' => 'M']]],
                ['sku' => '', 'name' => 'Mystery', 'quantity' => 1],
                ['sku' => 'ZERO', 'name' => 'Zero', 'quantity' => 0],
            ]],
        ];

        $result = (new PickListBuilder)->build($orders);

        $this->assertSame(['MUG', 'TEE', 'TEE', ''], array_column($result['lines'], 'sku'));
        $this->assertSame(['A2', 'A10', 'A10', ''], array_column($result['lines'], 'location'));
        $this->assertSame(8, $result['totalQuantity']);
        $medium = collect($result['lines'])->first(fn (array $line): bool => $line['options'] === 'Size: M');
        $this->assertSame(5, $medium['quantity']);
        $this->assertSame([['order' => '1001', 'quantity' => 2], ['order' => '1002', 'quantity' => 3]], $medium['allocations']);
        $this->assertNotEmpty(array_filter($result['warnings'], fn (string $warning): bool => str_contains($warning, 'Mystery') && str_contains($warning, 'no SKU')));
    }

    public function test_builder_does_not_combine_skuless_items_with_different_names_and_sorts_by_sku(): void
    {
        $result = (new PickListBuilder)->build([['orderNumber' => '1', 'items' => [['name' => 'B', 'quantity' => 1], ['name' => 'A', 'quantity' => 1], ['sku' => 'Z9', 'name' => 'z', 'quantity' => 1]]]], 'sku');

        $this->assertCount(3, $result['lines']);
        $this->assertSame(['', '', 'Z9'], array_column($result['lines'], 'sku'));
    }

    public function test_access_and_template_management_are_store_scoped(): void
    {
        $this->get('/print-queue/pick-list')->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get('/print-queue/pick-list')->assertForbidden();

        [$operator, $store] = $this->userWithStore(true);
        $this->actingAs($operator)->post(route('print-queue.pick-list.templates.store'), ['name' => 'Bins', 'columns' => ['location', 'bogus'], 'sort' => 'location', 'size' => 'normal'])->assertSessionHasErrors('columns.1');
        $this->actingAs($operator)->post(route('print-queue.pick-list.templates.store'), ['name' => 'Bins', 'columns' => ['location', 'sku', 'quantity'], 'sort' => 'sku', 'size' => 'large'])->assertRedirect();
        $this->actingAs($operator)->post(route('print-queue.pick-list.templates.store'), ['name' => 'Bins', 'columns' => ['sku'], 'sort' => 'sku', 'size' => 'large']);
        $template = $store->pickListTemplates()->sole();
        $this->assertSame(['sku'], $template->columns);

        $this->actingAs($operator)->get(route('print-queue.pick-list', ['template' => $template->id]))->assertOk()->assertSee('Bins');

        $foreign = PickListTemplate::factory()->create();
        $this->actingAs($operator)->delete(route('print-queue.pick-list.templates.destroy', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->delete(route('print-queue.pick-list.templates.destroy', $template->id))->assertRedirect();
        $this->assertSame(0, $store->pickListTemplates()->count());
    }

    public function test_generates_page_and_csv_and_reports_missing_orders(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        foreach (['1001', '1002', '1003'] as $number) {
            $store->printQueueItems()->create(['order_number' => $number]);
        }
        $client = ShipStationFixtures::bindClient();
        $order = fn (string $number, int $quantity): array => ShipStationFixtures::order($number, ['items' => [['sku' => '=CMD', 'name' => 'Formula', 'quantity' => $quantity, 'warehouseLocation' => 'B1']]]);
        $client->shouldReceive('findByOrderNumber')->with('1001', 42)->andReturn([$order('1001', 2)]);
        $client->shouldReceive('findByOrderNumber')->with('1002', 42)->andReturn([$order('1002', 1)]);
        $client->shouldReceive('findByOrderNumber')->with('1003', 42)->andReturn([]);

        $this->actingAs($operator)->get(route('print-queue.pick-list', ['generate' => 1]))->assertOk()->assertSeeText('2 orders')->assertSeeText('3 units')->assertSeeText('Not found in ShipStation')->assertSeeText('1003')->assertSeeText('1001 × 2');

        $csv = $this->actingAs($operator)->get(route('print-queue.pick-list.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("Bin,SKU,Item,Variant,Qty,Orders\r\n", $csv);
        $this->assertStringContainsString("'=CMD", $csv);
        $this->assertStringContainsString('1001 x2; 1002 x1', $csv);
    }

    public function test_export_with_empty_queue_redirects_with_error(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->get(route('print-queue.pick-list.export'))->assertRedirect(route('print-queue.pick-list'))->assertSessionHasErrors('pick_list');
    }

    /** @param array<string, list<array<string, mixed>>> $ordersByNumber */
    private function fakeShipStation(array $ordersByNumber): void
    {
        $client = ShipStationFixtures::bindClient();
        foreach ($ordersByNumber as $number => $orders) {
            $client->shouldReceive('findByOrderNumber')->with((string) $number, 42)->andReturn($orders);
        }
    }

    /** @return array<string, mixed> */
    private function shipStationOrder(string $number, int $quantity, string $bin): array
    {
        return ShipStationFixtures::order($number, ['items' => [['sku' => 'SKU-A', 'name' => 'Widget', 'quantity' => $quantity, 'warehouseLocation' => $bin]]]);
    }

    public function test_the_extra_large_size_is_accepted_rendered_and_unknown_sizes_are_rejected(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $columns = ['sku', 'quantity'];
        $this->actingAs($operator)->post(route('print-queue.pick-list.templates.store'), ['name' => 'Huge', 'columns' => $columns, 'sort' => 'sku', 'size' => 'giant'])->assertSessionHasErrors('size');
        $this->actingAs($operator)->post(route('print-queue.pick-list.templates.store'), ['name' => 'Huge', 'columns' => $columns, 'sort' => 'sku', 'size' => 'xlarge'])->assertSessionHasNoErrors();
        $template = $store->pickListTemplates()->sole();
        $this->assertSame('xlarge', $template->size);

        $store->printQueueItems()->create(['order_number' => '1001']);
        $this->fakeShipStation(['1001' => [$this->shipStationOrder('1001', 1, 'A1')]]);

        $this->actingAs($operator)->get(route('print-queue.pick-list', ['template' => $template->id, 'generate' => 1]))->assertOk()->assertSee('text-3xl', false);
    }

    public function test_a_snapshot_keeps_the_pick_list_as_generated_and_is_store_scoped(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $template = PickListTemplate::factory()->for($store)->create(['name' => 'Bins', 'columns' => ['location', 'sku', 'quantity', 'orders'], 'size' => 'large']);
        foreach (['1001', '1002'] as $number) {
            $store->printQueueItems()->create(['order_number' => $number]);
        }
        $this->fakeShipStation(['1001' => [$this->shipStationOrder('1001', 2, 'A1')], '1002' => [$this->shipStationOrder('1002', 1, 'A1')]]);

        $response = $this->actingAs($operator)->post(route('print-queue.pick-list.snapshots.store', ['template' => $template->id]));
        $snapshot = $store->pickListSnapshots()->sole();
        $response->assertRedirect(route('print-queue.pick-list.snapshots.show', $snapshot->id));
        $this->assertSame(3, $snapshot->payload['totalQuantity']);

        // Neither the template nor the queue nor ShipStation can change what was saved.
        $template->update(['columns' => ['sku'], 'size' => 'normal']);
        $store->printQueueItems()->delete();
        $this->actingAs($operator)->get(route('print-queue.pick-list.snapshots.show', $snapshot->id))->assertOk()
            ->assertSeeText('3 units')->assertSeeText('1001 × 2')->assertSeeText('A1')->assertSee('text-xl', false);
        $csv = $this->actingAs($operator)->get(route('print-queue.pick-list.snapshots.export', $snapshot->id))->assertOk()->streamedContent();
        $this->assertStringContainsString("Bin,SKU,Qty,Orders\r\n", $csv);
        $this->assertStringContainsString('A1,SKU-A,3,"1001 x2; 1002 x1"', $csv);
        $this->actingAs($operator)->get(route('print-queue.pick-list'))->assertOk()->assertSeeText('Bins');

        $foreign = PickListSnapshot::factory()->create();
        $this->actingAs($operator)->get(route('print-queue.pick-list.snapshots.show', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->get(route('print-queue.pick-list.snapshots.export', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->delete(route('print-queue.pick-list.snapshots.destroy', $foreign->id))->assertNotFound();
        $this->actingAs($operator)->delete(route('print-queue.pick-list.snapshots.destroy', $snapshot->id))->assertRedirect(route('print-queue.pick-list'));
        $this->assertSame(0, $store->pickListSnapshots()->count());
        $this->assertNotNull($foreign->fresh());
    }

    public function test_snapshots_need_a_queue_the_right_role_and_respect_the_per_store_limit(): void
    {
        $this->post(route('print-queue.pick-list.snapshots.store'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->post(route('print-queue.pick-list.snapshots.store'))->assertForbidden();

        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        $this->actingAs($operator)->post(route('print-queue.pick-list.snapshots.store'))->assertSessionHasErrors('pick_list');
        $this->assertSame(0, $store->pickListSnapshots()->count());

        $store->printQueueItems()->create(['order_number' => '1001']);
        PickListSnapshot::factory()->count(PickListSnapshot::MAX_PER_STORE)->for($store)->create();
        $this->actingAs($operator)->post(route('print-queue.pick-list.snapshots.store'))->assertSessionHasErrors('pick_list');
        $this->assertSame(PickListSnapshot::MAX_PER_STORE, $store->pickListSnapshots()->count());
    }
}
