<?php

declare(strict_types=1);

use App\Domain\Orders\OrderTimelineBuilder;
use PHPUnit\Framework\TestCase;

final class OrderTimelineParityTest extends TestCase
{
    public function test_shared_timeline_order_matches_and_known_content_differences_stay_visible(): void
    {
        $order = [
            'created_at' => '2026-06-01T10:00:00Z', 'processed_at' => '2026-06-01T11:00:00Z',
            'financial_status' => 'paid', 'total_price' => '49.99', 'currency' => 'EUR', 'email' => 'buyer@example.com',
            'fulfillments' => [['created_at' => '2026-06-02T10:00:00Z', 'line_items' => [['quantity' => 2], ['quantity' => 1]], 'tracking_number' => 'TRACK', 'tracking_url' => 'javascript:alert(1)']],
        ];
        $method = new ReflectionMethod(OrderInsightPageLoader::class, 'buildOrderTimeline');
        $legacy = $method->invoke(null, $order, [], [], []);
        $laravel = (new OrderTimelineBuilder)->build($order, [], [], []);

        $this->assertSame(array_column($legacy, 'ts'), array_column($laravel, 'timestamp'));
        $this->assertSame(['fulfillment', 'payment', 'order_placed'], array_column($laravel, 'type'));
        $this->assertSame(['2 items', '$49.99', 'javascript:alert(1)'], [$legacy[0]['detail'], $legacy[1]['detail'], $legacy[0]['url']]);
        $this->assertSame(['3 items', '€49.99', ''], [$laravel[0]['detail'], $laravel[1]['detail'], $laravel[0]['url']]);
    }
}
