<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\OrderTypeClassifier;
use Tests\TestCase;

class OrderTypeClassifierTest extends TestCase
{
    public function test_it_supports_legacy_match_types_arrays_and_exclusions(): void
    {
        config(['order-types' => ['fallback' => 'Other', 'rules' => [
            ['name' => 'Prefix', 'match' => 'sku_starts_with', 'value' => ['x-', 'z-']],
            ['name' => 'Contains', 'match' => 'sku_contains', 'value' => 'main'],
            ['name' => 'Not Prefix', 'match' => 'sku_not_starts_with', 'value' => 'skip-'],
            ['name' => 'Title', 'match' => 'title_contains', 'value' => 'grinder'],
            ['name' => 'Vendor', 'match' => 'vendor_is', 'value' => 'Zerno'],
        ]]]);

        $type = (new OrderTypeClassifier)->classify(['line_items' => [['sku' => 'Z-MAIN', 'title' => 'Coffee Grinder', 'vendor' => 'zerno']]]);

        $this->assertSame('Prefix + Contains + Not Prefix + Title + Vendor', $type);
    }
}
