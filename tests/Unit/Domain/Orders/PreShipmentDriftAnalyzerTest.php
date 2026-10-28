<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderChannelComparator;
use App\Domain\Orders\PreShipmentDriftAnalyzer;
use PHPUnit\Framework\TestCase;

class PreShipmentDriftAnalyzerTest extends TestCase
{
    /** @return array<string, string|null> */
    private function address(array $overrides = []): array
    {
        return $overrides + ['name' => 'Jane Doe', 'company' => null, 'street_1' => '1 Main St', 'street_2' => 'Apt 4', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US', 'phone' => '+1 512 555 0100'];
    }

    /** @return array{id: string, status: string, skus: array<string, int>, unidentified_lines: bool, has_customs: bool, address: array<string, string|null>} */
    private function open(array $skus, array $overrides = []): array
    {
        return $overrides + ['id' => '1', 'status' => 'awaiting_shipment', 'skus' => $skus, 'unidentified_lines' => false, 'has_customs' => false, 'address' => $this->address()];
    }

    /** @return array{id: string, cancelled: bool, complete: bool, has_fulfillment_orders: bool, remaining: array<string, int>, unidentified_lines: bool, address: array<string, string|null>} */
    private function shopify(array $remaining, array $overrides = []): array
    {
        return $overrides + ['id' => 'gid://shopify/Order/1', 'cancelled' => false, 'complete' => true, 'has_fulfillment_orders' => true, 'remaining' => $remaining, 'unidentified_lines' => false, 'address' => $this->address()];
    }

    /** @return array<string, mixed> */
    private function one(array $shipStation, array $shopify): array
    {
        return (new PreShipmentDriftAnalyzer)->analyze(['1001' => $shipStation], ['1001' => $shopify])[0];
    }

    public function test_matching_remaining_quantities_are_a_match(): void
    {
        $row = $this->one([$this->open(['tee' => 2, 'mug' => 1])], [$this->shopify(['tee' => 2, 'mug' => 1])]);

        $this->assertSame('match', $row['state']);
        $this->assertSame([], $row['extra']);
        $this->assertSame([], $row['missing']);
        $this->assertSame([], $row['flags']);
    }

    public function test_removed_and_reduced_items_are_extra_and_weight_review_is_flagged(): void
    {
        $row = $this->one([$this->open(['tee' => 3, 'mug' => 1], ['has_customs' => true])], [$this->shopify(['tee' => 1])]);

        $this->assertSame('different', $row['state']);
        $this->assertSame(['mug' => 1, 'tee' => 2], $row['extra']);
        $this->assertSame([], $row['missing']);
        $this->assertSame(['weight_review', 'customs_review'], $row['flags']);
    }

    public function test_added_items_shopify_still_needs_are_reported_as_missing(): void
    {
        $row = $this->one([$this->open(['tee' => 1])], [$this->shopify(['tee' => 1, 'hat' => 2])]);

        $this->assertSame('different', $row['state']);
        $this->assertSame(['hat' => 2], $row['missing']);
        $this->assertSame(['weight_review'], $row['flags']);
    }

    public function test_a_valid_partial_order_is_not_compared_against_the_whole_original_order(): void
    {
        $row = $this->one([$this->open(['tee' => 1])], [$this->shopify(['tee' => 1])]);

        $this->assertSame('match', $row['state']);
    }

    public function test_split_open_shipstation_orders_are_summed_before_comparing(): void
    {
        $row = $this->one([$this->open(['tee' => 1], ['id' => 'a']), $this->open(['tee' => 1, 'mug' => 1], ['id' => 'b', 'status' => 'on_hold'])], [$this->shopify(['tee' => 2, 'mug' => 1])]);

        $this->assertSame('match', $row['state']);
        $this->assertSame(2, $row['shipstation_orders']);
        $this->assertSame(['awaiting_shipment', 'on_hold'], $row['statuses']);
        $this->assertSame(['split_in_shipstation'], $row['flags']);
        $this->assertSame([], $row['address_changes']);
    }

    public function test_a_fully_fulfilled_shopify_order_makes_every_open_shipstation_item_extra(): void
    {
        $row = $this->one([$this->open(['tee' => 1])], [$this->shopify([])]);

        $this->assertSame('different', $row['state']);
        $this->assertSame(['tee' => 1], $row['extra']);
    }

    public function test_cancelled_shopify_order_is_a_difference_even_when_quantities_agree(): void
    {
        $row = $this->one([$this->open(['tee' => 1])], [$this->shopify(['tee' => 1], ['cancelled' => true])]);

        $this->assertSame('different', $row['state']);
        $this->assertContains('shopify_cancelled', $row['flags']);
    }

    public function test_unreadable_ambiguous_or_missing_shopify_data_stays_unknown_and_never_matches(): void
    {
        $analyzer = new PreShipmentDriftAnalyzer;
        $open = [$this->open(['tee' => 1])];
        $rows = $analyzer->analyze(
            ['1' => $open, '2' => $open, '3' => $open, '4' => $open],
            ['2' => [$this->shopify(['tee' => 1]), $this->shopify(['tee' => 1])], '3' => [$this->shopify(['tee' => 1], ['complete' => false])], '4' => [$this->shopify([], ['has_fulfillment_orders' => false])]],
        );

        $this->assertSame(['unknown', 'unknown', 'unknown', 'unknown'], array_column($rows, 'state'));
        $this->assertSame([['shopify_order_not_found'], ['shopify_order_ambiguous'], ['shopify_lines_incomplete'], ['shopify_fulfillment_orders_unavailable']], array_column($rows, 'reasons'));
        $this->assertSame([[], [], [], []], array_column($rows, 'extra'));
    }

    public function test_lines_without_sku_cannot_be_confirmed_but_a_real_difference_is_still_reported(): void
    {
        $unconfirmed = $this->one([$this->open(['tee' => 1], ['unidentified_lines' => true])], [$this->shopify(['tee' => 1])]);
        $different = $this->one([$this->open(['tee' => 2], ['unidentified_lines' => true])], [$this->shopify(['tee' => 1])]);

        $this->assertSame(['unknown', ['lines_without_sku']], [$unconfirmed['state'], $unconfirmed['reasons']]);
        $this->assertSame('different', $different['state']);
    }

    public function test_address_changes_distinguish_formatting_from_substantive_differences(): void
    {
        $row = $this->one(
            [$this->open(['tee' => 1], ['address' => $this->address(['street_1' => '1 main st.', 'street_2' => null, 'phone' => '+1 (512) 555-0100'])])],
            [$this->shopify(['tee' => 1])],
        );

        $byField = array_column($row['address_changes'], null, 'field');
        $this->assertSame('address', $row['state']);
        $this->assertSame('formatting', $byField['street_1']['kind']);
        $this->assertSame(['substantive', 'Apt 4', ''], [$byField['street_2']['kind'], $byField['street_2']['shopify'], $byField['street_2']['shipstation']]);
        $this->assertSame('formatting', $byField['phone']['kind']);
        $this->assertSame(['street_1', 'street_2', 'phone'], array_keys($byField));
    }

    public function test_formatting_only_address_changes_do_not_change_a_matching_result(): void
    {
        $row = $this->one([$this->open(['tee' => 1], ['address' => $this->address(['city' => 'AUSTIN'])])], [$this->shopify(['tee' => 1])]);

        $this->assertSame('match', $row['state']);
        $this->assertSame('formatting', $row['address_changes'][0]['kind']);
    }

    public function test_comparator_address_differences_treat_missing_and_blank_values_as_equal(): void
    {
        $comparator = new OrderChannelComparator;

        $this->assertSame([], $comparator->addressDifferences(['company' => '  '], ['company' => null]));
        $this->assertSame('substantive', $comparator->addressDifferences(['postal_code' => '78701'], ['postal_code' => '78702'])[0]['kind']);
    }
}
