<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\ShipStationOrderUpdatePayload;
use PHPUnit\Framework\TestCase;

class ShipStationOrderUpdatePayloadTest extends TestCase
{
    public function test_only_updatable_fields_are_kept_so_read_only_fields_are_never_sent_back(): void
    {
        $payload = ShipStationOrderUpdatePayload::build(['orderId' => 7, 'orderNumber' => '1001', 'shipTo' => ['name' => 'Jane'], 'createDate' => '2026-10-01', 'modifyDate' => '2026-10-02', 'orderTotal' => 10.0, 'userId' => 'abc']);

        $this->assertSame(['orderId' => 7, 'orderNumber' => '1001', 'shipTo' => ['name' => 'Jane']], $payload);
    }

    public function test_changes_replace_values_and_can_add_whitelisted_fields(): void
    {
        $payload = ShipStationOrderUpdatePayload::build(['orderId' => 7, 'orderStatus' => 'awaiting_shipment'], ['orderStatus' => 'on_hold', 'tagIds' => [3]]);

        $this->assertSame(['orderId' => 7, 'orderStatus' => 'on_hold', 'tagIds' => [3]], $payload);
    }
}
