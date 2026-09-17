<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'order_number', 'reason', 'ignored_at'])]
class IgnoredOrder extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['ignored_at' => 'date'];
    }
}
