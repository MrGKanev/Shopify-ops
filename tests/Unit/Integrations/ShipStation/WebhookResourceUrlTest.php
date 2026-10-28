<?php

namespace Tests\Unit\Integrations\ShipStation;

use App\Integrations\ShipStation\WebhookResourceUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookResourceUrlTest extends TestCase
{
    public function test_order_notify_resolves_to_the_orders_path_with_the_import_batch(): void
    {
        $result = (new WebhookResourceUrl)->parse('https://ssapi.shipstation.com/orders?storeID=42&importBatch=abc-123', 'ORDER_NOTIFY', 42);

        $this->assertSame(['path' => '/orders', 'query' => ['storeId' => 42, 'importBatch' => 'abc-123']], $result);
    }

    public function test_ship_notify_resolves_to_the_shipments_path_and_always_requests_items(): void
    {
        $result = (new WebhookResourceUrl)->parse('https://ssapi.shipstation.com:443/shipments?batchId=77&includeShipmentItems=false', 'SHIP_NOTIFY', 9);

        $this->assertSame(['path' => '/shipments', 'query' => ['storeId' => 9, 'batchId' => '77', 'includeShipmentItems' => 'true']], $result);
    }

    public function test_the_store_comes_from_our_side_even_when_the_url_omits_it(): void
    {
        $result = (new WebhookResourceUrl)->parse('https://ssapi.shipstation.com/orders?importBatch=b1', 'ORDER_NOTIFY', 5);

        $this->assertSame(5, $result['query']['storeId']);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function rejectedUrls(): iterable
    {
        yield 'plain http' => ['http://ssapi.shipstation.com/orders?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'foreign host' => ['https://evil.example.com/orders?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'look-alike host' => ['https://ssapi.shipstation.com.evil.example/orders?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'non-default port' => ['https://ssapi.shipstation.com:8443/orders?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'user info' => ['https://user:pw@ssapi.shipstation.com/orders?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'fragment' => ['https://ssapi.shipstation.com/orders?importBatch=b1#x', 'ORDER_NOTIFY', 1];
        yield 'wrong path for topic' => ['https://ssapi.shipstation.com/shipments?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'path traversal' => ['https://ssapi.shipstation.com/orders/../stores?importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'unsupported topic' => ['https://ssapi.shipstation.com/orders?importBatch=b1', 'ITEM_ORDER_NOTIFY', 1];
        yield 'unknown parameter' => ['https://ssapi.shipstation.com/orders?importBatch=b1&pageSize=500', 'ORDER_NOTIFY', 1];
        yield 'duplicate parameter' => ['https://ssapi.shipstation.com/orders?importBatch=b1&importBatch=b2', 'ORDER_NOTIFY', 1];
        yield 'empty value' => ['https://ssapi.shipstation.com/orders?importBatch=', 'ORDER_NOTIFY', 1];
        yield 'oversized value' => ['https://ssapi.shipstation.com/orders?importBatch='.'a', 'ORDER_NOTIFY', 1];
        yield 'another store' => ['https://ssapi.shipstation.com/orders?storeId=2&importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'non numeric store' => ['https://ssapi.shipstation.com/orders?storeId=1x&importBatch=b1', 'ORDER_NOTIFY', 1];
        yield 'missing batch' => ['https://ssapi.shipstation.com/orders?storeId=1', 'ORDER_NOTIFY', 1];
        yield 'ship batch on order topic' => ['https://ssapi.shipstation.com/orders?batchId=b1', 'ORDER_NOTIFY', 1];
        yield 'batch with symbols' => ['https://ssapi.shipstation.com/orders?importBatch=b1%0Ax', 'ORDER_NOTIFY', 1];
        yield 'unparsable' => ['https:///orders', 'ORDER_NOTIFY', 1];
    }

    #[DataProvider('rejectedUrls')]
    public function test_untrusted_resource_urls_are_rejected(string $url, string $topic, int $storeId): void
    {
        if ($url === 'https://ssapi.shipstation.com/orders?importBatch=a') {
            $url .= str_repeat('a', 200);
        }

        $this->expectException(InvalidArgumentException::class);
        (new WebhookResourceUrl)->parse($url, $topic, $storeId);
    }

    public function test_an_oversized_parameter_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WebhookResourceUrl)->parse('https://ssapi.shipstation.com/orders?importBatch='.str_repeat('a', 201), 'ORDER_NOTIFY', 1);
    }
}
