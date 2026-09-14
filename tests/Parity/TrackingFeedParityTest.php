<?php

declare(strict_types=1);

use App\Domain\Orders\TrackingFeedBuilder;
use PHPUnit\Framework\TestCase;

final class TrackingFeedParityTest extends TestCase
{
    public function test_tracking_result_matches_legacy(): void
    {
        $orders = [[
            'orderId' => '42',
            'orderStatus' => 'shipped',
            'carrierCode' => 'ups',
            'serviceCode' => 'ups_ground',
            'trackingNumber' => 'ABC 123',
            'shipDate' => '2026-06-01T14:30:00Z',
        ]];
        $method = new ReflectionMethod(SearchLookupPageLoader::class, 'buildTrackingResult');
        $legacy = $method->invoke(null, '1001', $orders);
        $laravel = (new TrackingFeedBuilder)->build('1001', $orders, $orders);

        $this->assertSame($legacy, $laravel);
    }

    public function test_missing_and_unshipped_results_match_legacy(): void
    {
        $method = new ReflectionMethod(SearchLookupPageLoader::class, 'buildTrackingResult');
        $builder = new TrackingFeedBuilder;

        $this->assertSame($method->invoke(null, '404', []), $builder->build('404', [], []));
        $order = [['orderId' => '43', 'orderStatus' => 'awaiting_shipment']];
        $this->assertSame($method->invoke(null, '1002', $order), $builder->build('1002', $order, []));
    }
}
