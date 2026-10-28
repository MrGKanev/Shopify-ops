<?php

namespace Tests\Feature;

use App\Models\ConsolidationDecision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\Support\ShipStationFixtures;
use Tests\TestCase;

class OrderConsolidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function order(int $id, array $overrides = []): array
    {
        return $overrides + ['orderId' => $id, 'orderNumber' => (string) (1000 + $id), 'orderStatus' => 'awaiting_shipment', 'orderDate' => '2026-10-26T10:00:00', 'customerEmail' => 'jane@example.com', 'advancedOptions' => ['storeId' => 42, 'warehouseId' => 9], 'requestedShippingService' => 'Priority', 'confirmation' => 'none', 'shippingAmount' => 0, 'weight' => ['value' => 8, 'units' => 'ounces'], 'shipTo' => ['name' => 'Jane Doe', 'street1' => '1 Main St.', 'street2' => 'Apt 4', 'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701', 'country' => 'US'], 'items' => [['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 1], ['sku' => null, 'name' => 'Discount', 'quantity' => 1, 'adjustment' => true]]];
    }

    private function backlog(array $orders): void
    {
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->with(42)->andReturn($orders);
    }

    public function test_page_is_gated_and_lists_a_bundle_with_assumption_based_savings(): void
    {
        $this->get(route('print-queue.consolidation'))->assertRedirect(route('login'));
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->get(route('print-queue.consolidation'))->assertForbidden();

        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->backlog([$this->order(1), $this->order(2), $this->order(3, ['customerEmail' => 'other@example.com'])]);

        $this->actingAs($operator)->get(route('print-queue.consolidation'))->assertOk()->assertDontSeeText('jane@example.com');
        $response = $this->get(route('print-queue.consolidation', ['generate' => 1, 'separate' => '5.95', 'combined' => '7.00']))->assertOk()->assertSeeText('jane@example.com')->assertSeeText('1001 + 1002')->assertDontSeeText('other@example.com');

        $bundle = $response->viewData('result')['groups'][0]['bundles'][0];
        $this->assertSame('4.90', $bundle['savings']);
        $this->assertSame([2], array_column($bundle['lines'], 'quantity'));
        $this->assertSame(16.0, $bundle['weight']['value']);
    }

    public function test_savings_stay_unknown_without_both_assumptions(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->backlog([$this->order(1), $this->order(2)]);

        $response = $this->actingAs($operator)->get(route('print-queue.consolidation', ['generate' => 1, 'separate' => '5']))->assertOk();

        $this->assertNull($response->viewData('result')['groups'][0]['bundles'][0]['savings']);
        $this->get(route('print-queue.consolidation', ['separate' => '-1']))->assertSessionHasErrors('separate');
    }

    public function test_decisions_are_validated_store_scoped_and_change_the_outcome(): void
    {
        [$operator, $store] = $this->userWithStore(true, ['store_number' => '42']);
        ConsolidationDecision::factory()->create(['order_number' => '1002', 'decision' => 'excluded']);
        $this->actingAs($operator);

        $this->post(route('print-queue.consolidation.decide'), ['order_number' => '1002; drop', 'decision' => 'excluded'])->assertSessionHasErrors('order_number');
        $this->post(route('print-queue.consolidation.decide'), ['order_number' => '1002', 'decision' => 'bogus'])->assertSessionHasErrors('decision');
        $this->post(route('print-queue.consolidation.decide'), ['order_number' => '#1002', 'decision' => 'excluded'])->assertRedirect();
        $this->assertSame(['1002' => 'excluded'], $store->consolidationDecisions()->pluck('decision', 'order_number')->all());

        $this->backlog([$this->order(1), $this->order(2, ['shippingAmount' => 4.5]), $this->order(3)]);
        $groups = $this->get(route('print-queue.consolidation', ['generate' => 1]))->viewData('result')['groups'];
        $this->assertSame(['1001', '1003'], $groups[0]['bundles'][0]['orders']);

        $this->post(route('print-queue.consolidation.decide'), ['order_number' => '1002', 'decision' => 'reviewed'])->assertRedirect();
        $groups = $this->get(route('print-queue.consolidation', ['generate' => 1]))->viewData('result')['groups'];
        $this->assertSame(['1001', '1002', '1003'], $groups[0]['bundles'][0]['orders']);

        $this->post(route('print-queue.consolidation.decide'), ['order_number' => '1002', 'decision' => 'clear'])->assertRedirect();
        $this->assertSame(0, $store->consolidationDecisions()->count());
        $this->assertSame(1, ConsolidationDecision::query()->count());
        [$viewer] = $this->userWithStore();
        $this->actingAs($viewer)->post(route('print-queue.consolidation.decide'), ['order_number' => '1', 'decision' => 'excluded'])->assertForbidden();
    }

    public function test_export_lists_every_line_with_its_state_and_partner_orders(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $this->backlog([$this->order(1), $this->order(2), $this->order(3, ['orderStatus' => 'on_hold'])]);

        $csv = $this->actingAs($operator)->get(route('print-queue.consolidation.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('Group,"Customer email",Order,"Ships together with",State,Reasons,SKU,Item,Quantity', $csv);
        $this->assertStringContainsString('"1001 + 1002",eligible,,TEE,Tee,1', $csv);
        $this->assertStringContainsString('1003,,excluded,not_awaiting_shipment,TEE,Tee,1', $csv);
        $this->assertStringNotContainsString('Discount', $csv);
    }

    public function test_failures_do_not_leak_details(): void
    {
        [$operator] = $this->userWithStore(true, ['store_number' => '42']);
        $client = ShipStationFixtures::bindClient();
        $client->shouldReceive('fetchBacklogOrders')->andThrow(new RuntimeException('secret-token boom'));

        $this->actingAs($operator)->get(route('print-queue.consolidation', ['generate' => 1]))->assertOk()->assertSeeText(__('The consolidation review could not be completed. Try again.'))->assertDontSeeText('secret-token');
        $this->get(route('print-queue.consolidation.export'))->assertRedirect(route('print-queue.consolidation', ['window' => 3]))->assertSessionHasErrors('consolidation');
    }
}
