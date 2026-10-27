<?php

namespace Database\Factories;

use App\Domain\Orders\PickListBuilder;
use App\Models\PickListSnapshot;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickListSnapshot>
 */
class PickListSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'created_by' => null,
            'template_name' => null,
            'columns' => PickListBuilder::DEFAULT_COLUMNS,
            'sort' => 'location',
            'size' => 'normal',
            'payload' => [
                'lines' => [['sku' => 'SKU-1', 'name' => 'Widget', 'options' => '', 'location' => 'A1', 'image' => '', 'quantity' => 2, 'allocations' => [['order' => '1001', 'quantity' => 2]]]],
                'totalQuantity' => 2,
                'warnings' => [],
                'orderCount' => 1,
                'missing' => [],
                'ambiguous' => [],
            ],
        ];
    }
}
