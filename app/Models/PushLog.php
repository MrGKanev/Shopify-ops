<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'order_number', 'shopify_id', 'shipstation_order_id', 'pushed_at', 'status', 'error_category'])]
class PushLog extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['pushed_at' => 'datetime'];
    }
}
