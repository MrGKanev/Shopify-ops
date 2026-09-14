<?php

declare(strict_types=1);

use App\Domain\Reports\VoidedShipmentsAnalyzer;
use PHPUnit\Framework\TestCase;

final class VoidedShipmentsParityTest extends TestCase
{
    /** Pure field mapping + void_date-descending sort, including a missing shipTo address. */
    public function test_rows_match_legacy(): void
    {
        $shipments = [
            ['orderNumber' => '9001', 'shipmentId' => 'S1', 'trackingNumber' => 'T1', 'carrierCode' => 'ups', 'serviceCode' => 'ground', 'shipDate' => '2026-01-01T00:00:00Z', 'voidDate' => '2026-01-02T00:00:00Z', 'shipTo' => ['name' => 'A', 'city' => 'X', 'state' => 'CA', 'postalCode' => '90000', 'country' => 'US']],
            ['orderNumber' => '9002', 'shipmentId' => 'S2', 'trackingNumber' => 'T2', 'carrierCode' => 'fedex', 'serviceCode' => 'express', 'shipDate' => '2026-01-03T00:00:00Z', 'voidDate' => '2026-01-05T00:00:00Z', 'shipTo' => null],
        ];

        $legacyMethod = new ReflectionMethod(\OrderAnomalyPageLoader::class, 'buildFailedShipmentRows');
        $legacyRows = $legacyMethod->invoke(null, $shipments);

        $laravelRows = (new VoidedShipmentsAnalyzer())->analyze($shipments);

        $this->assertSame($legacyRows, $laravelRows);
    }
}
