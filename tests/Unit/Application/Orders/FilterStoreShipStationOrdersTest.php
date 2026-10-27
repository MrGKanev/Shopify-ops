<?php

namespace Tests\Unit\Application\Orders;

use App\Application\Orders\FilterStoreShipStationOrders;
use App\Application\Orders\VerifyShipStationShipmentOwnership;
use App\Integrations\Exceptions\UnexpectedResponse;
use App\Models\Store;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterStoreShipStationOrdersTest extends TestCase
{
    /** @return array<string, mixed> */
    private function order(array $overrides = []): array
    {
        return array_replace_recursive(['orderId' => 7, 'orderNumber' => '1001', 'advancedOptions' => ['storeId' => 42]], $overrides);
    }

    private function filter(array $orders, mixed $storeNumber = '42'): array
    {
        return (new FilterStoreShipStationOrders(new VerifyShipStationShipmentOwnership))->handle(new Store(['store_number' => $storeNumber]), $orders);
    }

    public function test_it_keeps_only_own_orders_and_preserves_their_order(): void
    {
        $first = $this->order(['orderId' => 1]);
        $last = $this->order(['orderId' => '3', 'advancedOptions' => ['storeId' => '42']]);

        $kept = $this->filter([$first, $this->order(['orderId' => 2, 'advancedOptions' => ['storeId' => 99]]), $last]);

        $this->assertSame([$first, $last], $kept);
    }

    public function test_an_empty_list_is_valid(): void
    {
        $this->assertSame([], $this->filter([]));
    }

    #[DataProvider('unreadableStoreIds')]
    public function test_every_order_must_carry_a_readable_store_even_a_possibly_foreign_one(mixed $storeId): void
    {
        $order = $this->order();
        $order['advancedOptions']['storeId'] = $storeId;

        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('store identity');
        $this->filter([$this->order(), $order]);
    }

    public static function unreadableStoreIds(): array
    {
        return ['null' => [null], 'zero' => [0], 'text' => ['abc'], 'boolean' => [true], 'float' => [42.0], 'array' => [[42]]];
    }

    public function test_an_order_without_advanced_options_fails(): void
    {
        $this->expectException(UnexpectedResponse::class);
        $this->filter([['orderId' => 7, 'orderNumber' => '1001']]);
    }

    #[DataProvider('invalidOwnIdentities')]
    public function test_an_own_order_needs_a_readable_id_and_a_number(array $overrides): void
    {
        $this->expectException(UnexpectedResponse::class);
        $this->expectExceptionMessage('order identity');
        $this->filter([array_replace($this->order(), $overrides)]);
    }

    public static function invalidOwnIdentities(): array
    {
        return ['no id' => [['orderId' => null]], 'zero id' => [['orderId' => 0]], 'text id' => [['orderId' => 'x']], 'float id' => [['orderId' => 7.0]], 'no number' => [['orderNumber' => null]], 'blank number' => [['orderNumber' => '  ']], 'numeric number' => [['orderNumber' => 1001]]];
    }

    public function test_a_foreign_order_with_a_broken_identity_is_dropped_without_failing(): void
    {
        $this->assertSame([], $this->filter([$this->order(['orderId' => 'x', 'orderNumber' => '', 'advancedOptions' => ['storeId' => 99]])]));
    }

    #[DataProvider('unusableStoreNumbers')]
    public function test_a_store_without_a_valid_store_number_is_a_configuration_error(mixed $storeNumber): void
    {
        $this->expectException(LogicException::class);
        $this->filter([$this->order()], $storeNumber);
    }

    public static function unusableStoreNumbers(): array
    {
        return ['null' => [null], 'empty' => [''], 'zero' => ['0'], 'text' => ['abc']];
    }
}
