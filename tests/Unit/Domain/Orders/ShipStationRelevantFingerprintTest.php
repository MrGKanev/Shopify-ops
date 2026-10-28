<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\ShipStationRelevantFingerprint;
use PHPUnit\Framework\TestCase;

class ShipStationRelevantFingerprintTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'shipTo' => ['name' => 'Jane  Doe', 'street1' => '1 Main St', 'street2' => 'Apt 4', 'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701', 'country' => 'US', 'phone' => '+1 512 555 0100'],
            'items' => [['lineItemKey' => 'gid://shopify/LineItem/2', 'sku' => 'MUG', 'name' => 'Mug', 'quantity' => 1], ['lineItemKey' => 'gid://shopify/LineItem/1', 'sku' => 'TEE', 'name' => 'Tee', 'quantity' => 2]],
            'requestedShippingService' => ' Priority ',
        ];
    }

    public function test_the_hash_ignores_case_whitespace_and_item_order(): void
    {
        $reordered = $this->payload(['items' => array_reverse($this->payload()['items']), 'shipTo' => ['name' => 'jane doe', 'street1' => ' 1 MAIN ST ', 'street2' => 'apt 4', 'city' => 'AUSTIN', 'state' => 'tx', 'postalCode' => '78701', 'country' => 'us', 'phone' => '+1 512 555 0100'], 'requestedShippingService' => 'Priority']);

        $this->assertSame(ShipStationRelevantFingerprint::hash($this->payload()), ShipStationRelevantFingerprint::hash($reordered));
    }

    public function test_adjustments_and_zero_quantity_lines_do_not_count(): void
    {
        $extra = $this->payload();
        $extra['items'][] = ['sku' => '', 'name' => 'Discount', 'quantity' => 1, 'adjustment' => true];
        $extra['items'][] = ['sku' => 'GONE', 'name' => 'Removed', 'quantity' => 0];

        $this->assertSame(ShipStationRelevantFingerprint::hash($this->payload()), ShipStationRelevantFingerprint::hash($extra));
    }

    public function test_items_are_keyed_by_the_line_item_id_tail_or_by_sku_and_name(): void
    {
        $fields = ShipStationRelevantFingerprint::fields($this->payload(['items' => [['lineItemKey' => 'gid://shopify/LineItem/9', 'sku' => 'A', 'quantity' => 1], ['sku' => ' B ', 'name' => ' Thing ', 'quantity' => 3]]]));

        $this->assertSame(['9', 'sku:B|Thing'], array_column($fields['items'], 'key'));
        $this->assertSame([1, 3], array_column($fields['items'], 'quantity'));
    }

    public function test_substantive_changes_change_the_hash(): void
    {
        $base = ShipStationRelevantFingerprint::hash($this->payload());
        $quantity = $this->payload();
        $quantity['items'][0]['quantity'] = 5;
        $address = $this->payload();
        $address['shipTo']['street2'] = '';

        $this->assertNotSame($base, ShipStationRelevantFingerprint::hash($quantity));
        $this->assertNotSame($base, ShipStationRelevantFingerprint::hash($address));
        $this->assertNotSame($base, ShipStationRelevantFingerprint::hash($this->payload(['requestedShippingService' => 'Ground'])));
    }

    public function test_non_scalar_address_values_are_treated_as_empty(): void
    {
        $fields = ShipStationRelevantFingerprint::fields(['shipTo' => ['name' => ['x'], 'city' => 'Austin']]);

        $this->assertSame('', $fields['shipTo']['name']);
        $this->assertSame('austin', $fields['shipTo']['city']);
    }

    public function test_diff_reports_original_values_for_changed_address_fields_and_item_changes(): void
    {
        $actual = $this->payload();
        $actual['shipTo']['street2'] = '';
        $actual['shipTo']['city'] = 'AUSTIN';
        $actual['items'][1]['quantity'] = 1;

        $diff = ShipStationRelevantFingerprint::diff($this->payload(), $actual);

        $this->assertSame(['shipTo.street2', 'items'], array_keys($diff));
        $this->assertSame(['shopify' => 'Apt 4', 'shipstation' => ''], $diff['shipTo.street2']);
        $this->assertSame([], ShipStationRelevantFingerprint::diff($this->payload(), $this->payload()));
    }
}
