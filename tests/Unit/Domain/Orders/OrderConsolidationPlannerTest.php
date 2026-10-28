<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderConsolidationPlanner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OrderConsolidationPlannerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function order(string $number, array $overrides = []): array
    {
        return $overrides + ['number' => $number, 'status' => 'awaiting_shipment', 'created_at' => CarbonImmutable::parse('2026-10-26 10:00:00'), 'email' => 'jane@example.com', 'recipient' => 'Jane Doe', 'destination' => '1 main st|apt 4|austin|tx|78701|us', 'warehouse' => '9', 'service' => 'usps_priority', 'confirmation' => 'none', 'paid_shipping' => false, 'international' => false, 'insured' => false, 'gift' => false, 'special' => false, 'weight' => ['value' => 8.0, 'units' => 'ounces'], 'lines' => [['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 1]]];
    }

    /** @return array<string, mixed> */
    private function plan(array $orders, int $window = 3, array $decisions = []): array
    {
        return (new OrderConsolidationPlanner)->plan($orders, $window, $decisions);
    }

    public function test_orders_sharing_customer_and_full_destination_form_a_bundle_with_source_allocations(): void
    {
        $result = $this->plan([
            $this->order('1001', ['lines' => [['sku' => 'TEE', 'name' => 'Tee', 'quantity' => 2], ['sku' => 'MUG', 'name' => 'Mug', 'quantity' => 1]]]),
            $this->order('1002', ['lines' => [['sku' => 'tee', 'name' => 'Tee', 'quantity' => 1]]]),
        ]);

        $this->assertCount(1, $result['groups']);
        $bundle = $result['groups'][0]['bundles'][0];
        $this->assertSame(['1001', '1002'], $bundle['orders']);
        $this->assertSame(1, $bundle['packages_avoided']);
        $this->assertSame(16.0, $bundle['weight']['value']);
        $byName = array_column($bundle['lines'], null, 'name');
        $this->assertSame([3, ['1001' => 2, '1002' => 1]], [$byName['Tee']['quantity'], $byName['Tee']['allocations']]);
        $this->assertSame(['eligible', 'eligible'], array_column($result['groups'][0]['members'], 'state'));
    }

    public function test_a_shared_address_alone_is_not_enough(): void
    {
        $result = $this->plan([
            $this->order('1001'),
            $this->order('1002', ['email' => 'other@example.com']),
            $this->order('1003', ['destination' => '1 main st||austin|tx|78701|us']),
            $this->order('1004', ['recipient' => 'John Roe']),
            $this->order('1005', ['email' => '']),
            $this->order('1006', ['destination' => null]),
        ]);

        $this->assertSame([], $result['groups']);
    }

    public function test_held_orders_and_manual_exclusions_are_listed_but_never_bundled(): void
    {
        $result = $this->plan(
            [$this->order('1001'), $this->order('1002', ['status' => 'on_hold']), $this->order('1003'), $this->order('1004')],
            3,
            ['1004' => 'excluded'],
        );

        $members = array_column($result['groups'][0]['members'], null, 'number');
        $this->assertSame(['1001', '1003'], $result['groups'][0]['bundles'][0]['orders']);
        $this->assertSame(['excluded', ['not_awaiting_shipment']], [$members['1002']['state'], $members['1002']['reasons']]);
        $this->assertSame(['excluded', ['manually_excluded']], [$members['1004']['state'], $members['1004']['reasons']]);
    }

    public function test_review_flags_keep_an_order_out_until_the_operator_marks_it_reviewed(): void
    {
        $orders = [$this->order('1001'), $this->order('1002', ['paid_shipping' => true, 'insured' => true, 'international' => true, 'gift' => true, 'special' => true])];

        $unreviewed = $this->plan($orders);
        $reviewed = $this->plan($orders, 3, ['1002' => 'reviewed']);

        $members = array_column($unreviewed['groups'][0]['members'], null, 'number');
        $this->assertSame('review', $members['1002']['state']);
        $this->assertSame(['paid_shipping', 'international_or_customs', 'insured', 'gift', 'special_handling'], $members['1002']['reasons']);
        $this->assertSame([], $unreviewed['groups'][0]['bundles']);
        $this->assertSame('alone', $members['1001']['state']);
        $this->assertSame(['1001', '1002'], $reviewed['groups'][0]['bundles'][0]['orders']);
    }

    public function test_different_ship_from_or_service_never_combine(): void
    {
        $result = $this->plan([$this->order('1001'), $this->order('1002', ['warehouse' => '10']), $this->order('1003', ['service' => 'ups_ground']), $this->order('1004', ['confirmation' => 'signature'])]);

        $this->assertSame([], $result['groups'][0]['bundles']);
        $this->assertSame(['alone', 'alone', 'alone', 'alone'], array_column($result['groups'][0]['members'], 'state'));
        $this->assertSame([['no_compatible_partner']], array_unique(array_column($result['groups'][0]['members'], 'reasons'), SORT_REGULAR));
    }

    public function test_the_waiting_window_limits_a_bundle_to_orders_placed_close_together(): void
    {
        $result = $this->plan([
            $this->order('1001', ['created_at' => CarbonImmutable::parse('2026-10-20 23:00:00')]),
            $this->order('1002', ['created_at' => CarbonImmutable::parse('2026-10-22 01:00:00')]),
            $this->order('1003', ['created_at' => CarbonImmutable::parse('2026-10-27 08:00:00')]),
        ], 2);

        $members = array_column($result['groups'][0]['members'], null, 'number');
        $this->assertSame(['1001', '1002'], $result['groups'][0]['bundles'][0]['orders']);
        $this->assertSame(['alone', ['outside_window']], [$members['1003']['state'], $members['1003']['reasons']]);
    }

    public function test_unknown_weights_stay_unknown_and_unspecified_profile_is_noted(): void
    {
        $result = $this->plan([
            $this->order('1001', ['weight' => null, 'service' => '', 'warehouse' => '']),
            $this->order('1002', ['service' => '', 'warehouse' => '']),
            $this->order('1003', ['service' => '', 'warehouse' => '', 'weight' => ['value' => 1.0, 'units' => 'grams']]),
        ]);

        $bundle = $result['groups'][0]['bundles'][0];
        $this->assertNull($bundle['weight']);
        $this->assertSame(['service_unspecified', 'ship_from_unspecified'], $bundle['notes']);
        $this->assertSame(2, $bundle['packages_avoided']);
    }

    public function test_lines_without_sku_are_combined_by_name_and_flagged(): void
    {
        $result = $this->plan([
            $this->order('1001', ['lines' => [['sku' => '', 'name' => 'Custom print', 'quantity' => 1]]]),
            $this->order('1002', ['lines' => [['sku' => '', 'name' => 'custom print', 'quantity' => 2]]]),
        ]);

        $bundle = $result['groups'][0]['bundles'][0];
        $this->assertTrue($bundle['unidentified_lines']);
        $this->assertSame(3, $bundle['lines'][0]['quantity']);
    }

    public function test_savings_need_both_assumptions_and_use_exact_decimals(): void
    {
        $planner = new OrderConsolidationPlanner;

        $this->assertSame('7.85', $planner->savings(3, '5.95', '10.00'));
        $this->assertNull($planner->savings(2, '5.95', null));
        $this->assertNull($planner->savings(2, null, '5'));
        $this->assertSame('-3.00', $planner->savings(2, '1.00', '5.00'));
    }
}
