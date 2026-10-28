<?php

namespace Tests\Support;

use App\Integrations\ShipStation\ShipStationClientContract;
use App\Integrations\ShipStation\ShipStationClientFactory;
use Mockery;
use Mockery\MockInterface;

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
     * A ShipStation client every store resolves to; configure its expectations in the test.
     *
     * @return MockInterface&ShipStationClientContract
     */
    public static function bindClient(): MockInterface
    {
        $client = Mockery::mock(ShipStationClientContract::class);
        $factory = Mockery::mock(ShipStationClientFactory::class);
        $factory->shouldReceive('forStore')->andReturn($client);
        app()->instance(ShipStationClientFactory::class, $factory);

        return $client;
    }
}
