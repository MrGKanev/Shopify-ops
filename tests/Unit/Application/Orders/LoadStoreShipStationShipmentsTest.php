<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\LoadStoreShipStationShipments;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoadStoreShipStationShipmentsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function shipment(array $overrides = []): array
    {
        return array_replace_recursive(['shipmentId' => 100, 'orderId' => 7], $overrides);
    }

    private function load(array $shipments, array $orders = [['orderId' => 7]], mixed $storeNumber = '42'): array
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('getOrderShipments')->with('1001', true)->andReturn($shipments);

        return (new LoadStoreShipStationShipments)->handle(new Store(['store_number' => $storeNumber]), $client, '1001', $orders);
    }

    public function test_without_orders_shipstation_is_not_called(): void
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldNotReceive('getOrderShipments');

        $this->assertSame([], (new LoadStoreShipStationShipments)->handle(new Store(['store_number' => '42']), $client, '1001', []));
    }

    public function test_only_shipments_of_the_given_orders_are_returned(): void
    {
        $own = $this->shipment();
        $second = $this->shipment(['shipmentId' => '101', 'orderId' => '7']);

        $loaded = $this->load([$own, $this->shipment(['shipmentId' => 200, 'orderId' => 8]), $second]);

        $this->assertSame([$own, $second], $loaded);
    }

    public function test_identifier_types_do_not_matter_when_matching_orders(): void
    {
        $this->assertCount(1, $this->load([$this->shipment(['orderId' => '7'])], [['orderId' => 7]]));
        $this->assertCount(1, $this->load([$this->shipment(['orderId' => 7])], [['orderId' => '7']]));
    }

    public function test_identical_duplicates_collapse_and_conflicting_duplicates_fail(): void
    {
        $shipment = $this->shipment();
        $this->assertSame([$shipment], $this->load([$shipment, $shipment]));

        $this->expectException(UnexpectedResponse::class);
        $this->load([$shipment, [...$shipment, 'trackingNumber' => 'OTHER']]);
    }

    #[DataProvider('unreadableShipmentOrderIds')]
    public function test_a_shipment_without_a_readable_order_id_fails_even_if_it_might_be_foreign(mixed $orderId): void
    {
        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('ownership');
        $this->load([$this->shipment(['orderId' => $orderId])]);
    }

    public static function unreadableShipmentOrderIds(): array
    {
        return ['null' => [null], 'array' => [[7]], 'zero' => [0], 'text' => ['abc'], 'boolean' => [true]];
    }

    #[DataProvider('unreadableShipmentIds')]
    public function test_a_shipment_of_a_listed_order_needs_a_readable_shipment_id(mixed $shipmentId): void
    {
        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('identity');
        $this->load([$this->shipment(['shipmentId' => $shipmentId])]);
    }

    public static function unreadableShipmentIds(): array
    {
        return ['null' => [null], 'zero' => [0], 'text' => ['abc'], 'boolean' => [true]];
    }

    public function test_a_shipment_that_names_another_store_fails_but_one_without_a_store_is_accepted(): void
    {
        $this->assertCount(1, $this->load([$this->shipment()]));
        $this->assertCount(1, $this->load([$this->shipment(['advancedOptions' => ['storeId' => '42']])]));

        $this->expectException(UnexpectedResponse::class);
        $this->load([$this->shipment(['advancedOptions' => ['storeId' => 99]])]);
    }

    public function test_a_store_without_a_store_number_rejects_any_shipment_that_names_a_store(): void
    {
        $this->assertCount(1, $this->load([$this->shipment()], storeNumber: null));

        $this->expectException(UnexpectedResponse::class);
        $this->load([$this->shipment(['advancedOptions' => ['storeId' => 42]])], storeNumber: null);
    }

    public function test_a_foreign_shipment_with_a_broken_identity_is_ignored(): void
    {
        $this->assertSame([], $this->load([$this->shipment(['orderId' => 8, 'shipmentId' => 'bad', 'advancedOptions' => ['storeId' => 99]])]));
    }
}
