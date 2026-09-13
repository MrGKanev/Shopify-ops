<?php

declare(strict_types=1);

use App\Integrations\ShipStation\ShipStationClient;
use PHPUnit\Framework\TestCase;

final class PushToShipStationParityTest extends TestCase
{
    public function test_build_payload_matches_legacy_for_a_full_order(): void
    {
        $order = $this->order();

        $legacy = new \ShipStation('key', 'secret');
        $laravel = new ShipStationClient('key', 'secret');

        $this->assertSame($legacy->buildPayload($order), $laravel->buildOrderPayload($order));
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'order_number' => '#1001',
            'name' => '#1001',
            'created_at' => '2026-09-01T10:00:00Z',
            'email' => 'jane@example.com',
            'billing_address' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company' => null,
                'address1' => '123 Main St',
                'address2' => null,
                'city' => 'Austin',
                'province_code' => 'TX',
                'zip' => '78701',
                'country_code' => 'US',
                'phone' => '5551234567',
            ],
            'shipping_address' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company' => null,
                'address1' => '456 Oak Ave',
                'address2' => 'Apt 2',
                'city' => 'Austin',
                'province_code' => 'TX',
                'zip' => '78702',
                'country_code' => 'US',
                'phone' => '5559876543',
            ],
            'line_items' => [
                ['id' => 111, 'title' => 'Widget', 'sku' => 'WID-1', 'quantity' => 2, 'price' => '19.99'],
                ['id' => 112, 'title' => 'Gadget', 'sku' => 'GAD-1', 'quantity' => 1, 'price' => '9.99'],
            ],
            'shipping_lines' => [
                ['price' => '5.00'],
            ],
            'total_price' => '54.97',
            'total_tax' => '4.50',
        ];
    }
}
