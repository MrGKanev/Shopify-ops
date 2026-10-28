<?php

namespace Tests\Support;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Models\Store;
use Mockery;
use Mockery\MockInterface;
use Throwable;

/** Payload builders and wiring for the ShipStation seam, shared so contract changes touch one file. */
class ShipStationFixtures
{
    public const STORE_NUMBER = 42;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function order(string $number, array $overrides = []): array
    {
        return $overrides + ['orderId' => (int) $number, 'orderNumber' => $number, 'advancedOptions' => ['storeId' => self::STORE_NUMBER], 'items' => []];
    }

    /**
     * The minimal order a ShipStation lookup returns for a store.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function lookupOrder(int $orderId, string $number, int $storeId = self::STORE_NUMBER, array $extra = []): array
    {
        return ['orderId' => $orderId, 'orderNumber' => $number, 'advancedOptions' => ['storeId' => $storeId]] + $extra;
    }

    /**
     * A ShipStation client the factory resolves to; configure its expectations in the test.
     * Pass $times to assert how often the factory is asked, and $store to assert for which store.
     *
     * @return MockInterface&ShipStationClientContract
     */
    public static function bindClient(?int $times = null, ?Store $store = null): MockInterface
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $expectation = $factory->shouldReceive('forStore');
        if ($times !== null) {
            $expectation->times($times);
        }
        if ($store !== null) {
            $expectation->with(Mockery::on(fn (Store $candidate): bool => $candidate->is($store)));
        }
        $expectation->andReturn($client);
        app()->instance(ShipStationClientFactory::class, $factory);

        return $client;
    }

    /** A factory that finds no ShipStation credentials for the store. */
    public static function bindUnconfigured(?int $times = null): void
    {
        self::bindFactory(fn ($expectation) => $expectation->andReturn(null), $times);
    }

    /** A factory whose client cannot be built, as when the stored credentials are rejected. */
    public static function bindFailure(Throwable $exception, ?int $times = null): void
    {
        self::bindFactory(fn ($expectation) => $expectation->andThrow($exception), $times);
    }

    private static function bindFactory(callable $configure, ?int $times): void
    {
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $expectation = $factory->shouldReceive('forStore');
        if ($times !== null) {
            $expectation->times($times);
        }
        $configure($expectation);
        app()->instance(ShipStationClientFactory::class, $factory);
    }
}
