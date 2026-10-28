<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\FindStoreShipStationOrders;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Integrations\ShipStation\ShipStationClientContract;
use App\Models\Store;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShipStationFixtures;

class FindStoreShipStationOrdersTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $orders */
    private function client(array $orders, string $number = '1001'): ShipStationClientContract
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldReceive('findByOrderNumber')->once()->with($number, 42)->andReturn($orders);

        return $client;
    }

    /** @return array<string, mixed> */
    private function order(array $overrides = []): array
    {
        return array_replace_recursive(ShipStationFixtures::lookupOrder(7, '1001'), $overrides);
    }

    private function find(array $orders, string $number = '1001', mixed $storeNumber = '42'): array
    {
        return (new FindStoreShipStationOrders)->handle(new Store(['store_number' => $storeNumber]), $this->client($orders, $number), $number);
    }

    public function test_it_returns_only_orders_with_the_exact_number_that_belong_to_the_store(): void
    {
        $own = $this->order();
        $matches = $this->find([$own, $this->order(['orderId' => 8, 'orderNumber' => '1001-B']), $this->order(['orderId' => 9, 'advancedOptions' => ['storeId' => 99]])]);

        $this->assertSame([$own], $matches);
    }

    public function test_hash_prefixes_are_ignored_on_both_sides(): void
    {
        $this->assertCount(1, $this->find([$this->order(['orderNumber' => '#1001'])], '1001'));
        $this->assertCount(1, $this->find([$this->order(['orderNumber' => '1001'])], '#1001'));
    }

    public function test_string_and_integer_identifiers_are_equivalent(): void
    {
        $matches = $this->find([$this->order(['orderId' => '7', 'advancedOptions' => ['storeId' => '42']]), $this->order(['orderId' => 8, 'advancedOptions' => ['storeId' => 42]])]);

        $this->assertCount(2, $matches);
    }

    public function test_several_exact_matches_are_all_returned_for_the_caller_to_treat_as_ambiguous(): void
    {
        $this->assertCount(2, $this->find([$this->order(), $this->order(['orderId' => 8])]));
    }

    public function test_orders_with_another_number_are_skipped_even_when_their_store_is_unreadable(): void
    {
        $unrelated = ['orderId' => 8, 'orderNumber' => '2002'];

        $this->assertSame([], $this->find([$unrelated, ['orderNumber' => '2002', 'advancedOptions' => ['storeId' => true]]]));
    }

    #[DataProvider('unreadableStoreIds')]
    public function test_an_exact_match_without_a_readable_store_fails_instead_of_being_guessed(mixed $storeId): void
    {
        $order = $this->order();
        $order['advancedOptions']['storeId'] = $storeId;

        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('store identity');
        $this->find([$order]);
    }

    public static function unreadableStoreIds(): array
    {
        return ['null' => [null], 'zero' => [0], 'zero string' => ['0'], 'negative' => [-4], 'text' => ['abc'], 'boolean' => [true], 'float' => [42.0], 'array' => [[42]], 'padded' => [' 42'], 'empty' => ['']];
    }

    public function test_an_exact_match_without_any_advanced_options_fails(): void
    {
        $this->expectException(UnexpectedResponse::class);
        $this->find([['orderId' => 7, 'orderNumber' => '1001']]);
    }

    #[DataProvider('invalidOrderIds')]
    public function test_an_own_order_with_an_unreadable_order_id_fails(mixed $orderId): void
    {
        $order = $this->order();
        $order['orderId'] = $orderId;

        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('order identity');
        $this->find([$order]);
    }

    public static function invalidOrderIds(): array
    {
        return ['null' => [null], 'zero' => [0], 'text' => ['abc'], 'boolean' => [true], 'float' => [7.0]];
    }

    public function test_a_foreign_order_with_an_unreadable_order_id_is_skipped_before_its_identity_matters(): void
    {
        $this->assertSame([], $this->find([$this->order(['orderId' => 'x', 'advancedOptions' => ['storeId' => 99]])]));
    }

    #[DataProvider('unusableStoreNumbers')]
    public function test_a_store_without_a_valid_store_number_never_reaches_shipstation(mixed $storeNumber): void
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $client->shouldNotReceive('findByOrderNumber');

        $this->expectException(LogicException::class);
        (new FindStoreShipStationOrders)->handle(new Store(['store_number' => $storeNumber]), $client, '1001');
    }

    public static function unusableStoreNumbers(): array
    {
        return ['null' => [null], 'empty' => [''], 'zero' => ['0'], 'text' => ['abc'], 'negative' => ['-1']];
    }
}
