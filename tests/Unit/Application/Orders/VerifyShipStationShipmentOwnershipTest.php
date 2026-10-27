<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\VerifyShipStationShipmentOwnership;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VerifyShipStationShipmentOwnershipTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function shipment(array $overrides = []): array
    {
        return array_replace_recursive(['shipmentId' => 100, 'orderId' => 7, 'orderNumber' => '1001'], $overrides);
    }

    /** @return array<string, mixed> */
    private function parent(array $overrides = []): array
    {
        return array_replace_recursive(['orderId' => 7, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 42]], $overrides);
    }

    private function client(array $parents): MockInterface
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        foreach ($parents as $orderId => $parent) {
            $client->shouldReceive('getOrder')->once()->with($orderId)->andReturn($parent);
        }

        return $client;
    }

    private function verify(array $shipments, MockInterface $client, mixed $storeNumber = '42'): array
    {
        return (new VerifyShipStationShipmentOwnership)->handle(new Store(['store_number' => $storeNumber]), $client, $shipments);
    }

    #[DataProvider('unusableStoreNumbers')]
    public function test_store_id_requires_a_positive_integer_store_number(mixed $storeNumber): void
    {
        $this->expectException(LogicException::class);
        (new VerifyShipStationShipmentOwnership)->storeId(new Store(['store_number' => $storeNumber]));
    }

    public static function unusableStoreNumbers(): array
    {
        return ['null' => [null], 'empty' => [''], 'zero' => ['0'], 'text' => ['4x'], 'negative' => ['-2']];
    }

    public function test_store_id_returns_the_integer_store_number(): void
    {
        $this->assertSame(42, (new VerifyShipStationShipmentOwnership)->storeId(new Store(['store_number' => '42'])));
        $this->assertSame(42, (new VerifyShipStationShipmentOwnership)->storeId(new Store(['store_number' => 42])));
    }

    public function test_an_unconfigured_store_fails_before_any_shipstation_call(): void
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldNotReceive('getOrder');

        $this->expectException(LogicException::class);
        $this->verify([$this->shipment()], $client, null);
    }

    public function test_shipments_of_a_confirmed_parent_order_are_returned_and_each_parent_is_read_once(): void
    {
        $client = $this->client([7 => $this->parent(), 8 => $this->parent(['orderId' => 8, 'orderNumber' => '1002'])]);
        $shipments = [$this->shipment(['shipmentId' => 100]), $this->shipment(['shipmentId' => '101']), $this->shipment(['shipmentId' => 102, 'orderId' => '8', 'orderNumber' => '#1002'])];

        $this->assertSame($shipments, $this->verify($shipments, $client));
    }

    public function test_an_empty_list_makes_no_calls(): void
    {
        $this->assertSame([], $this->verify([], Mockery::mock(ShipStationClientContract::class)));
    }

    public function test_an_identical_duplicate_shipment_is_returned_once_and_a_conflicting_one_fails(): void
    {
        $shipment = $this->shipment();
        $this->assertSame([$shipment], $this->verify([$shipment, $shipment], $this->client([7 => $this->parent()])));

        $this->expectException(UnexpectedResponse::class);
        $this->verify([$shipment, [...$shipment, 'trackingNumber' => 'OTHER']], $this->client([7 => $this->parent()]));
    }

    #[DataProvider('brokenShipmentIdentities')]
    public function test_a_shipment_without_a_readable_id_or_order_id_fails_before_reading_the_parent(array $overrides): void
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldNotReceive('getOrder');

        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('Shipment identity');
        $this->verify([array_replace($this->shipment(), $overrides)], $client);
    }

    public static function brokenShipmentIdentities(): array
    {
        return ['no shipment id' => [['shipmentId' => null]], 'zero shipment id' => [['shipmentId' => 0]], 'text shipment id' => [['shipmentId' => 'abc']], 'array shipment id' => [['shipmentId' => [1]]], 'boolean order id' => [['orderId' => true]], 'no order id' => [['orderId' => null]], 'float order id' => [['orderId' => 7.5]]];
    }

    #[DataProvider('parentMismatches')]
    public function test_a_parent_that_is_not_this_store_or_this_order_is_rejected(array $parent, array $shipment): void
    {
        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('ownership');
        $this->verify([$this->shipment($shipment)], $this->client([7 => $this->parent($parent)]));
    }

    public static function parentMismatches(): array
    {
        return [
            'parent from another store' => [['advancedOptions' => ['storeId' => 99]], []],
            'parent without store' => [['advancedOptions' => ['storeId' => null]], []],
            'parent with a boolean store' => [['advancedOptions' => ['storeId' => true]], []],
            'parent with another id' => [['orderId' => 8], []],
            'parent with another number' => [['orderNumber' => '2002'], []],
            'shipment claims another store' => [[], ['advancedOptions' => ['storeId' => 99]]],
            'shipment claims a non numeric store' => [[], ['advancedOptions' => ['storeId' => 'x']]],
            'shipment number differs' => [[], ['orderNumber' => '1002']],
        ];
    }

    public function test_a_shipment_store_equal_to_the_store_and_a_missing_shipment_store_are_both_accepted(): void
    {
        $withStore = $this->shipment(['shipmentId' => 1, 'advancedOptions' => ['storeId' => '42']]);
        $without = $this->shipment(['shipmentId' => 2]);

        $this->assertSame([$withStore, $without], $this->verify([$withStore, $without], $this->client([7 => $this->parent()])));
    }

    public function test_hash_prefixes_do_not_make_order_numbers_differ(): void
    {
        $shipment = $this->shipment(['orderNumber' => '#1001']);

        $this->assertSame([$shipment], $this->verify([$shipment], $this->client([7 => $this->parent(['orderNumber' => '1001'])])));
    }
}
