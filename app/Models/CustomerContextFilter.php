<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\CustomerContextFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved customer-context rule: which orders to show, the VIP order count and this store's milestone order numbers.
 *
 * @property list<int> $milestones
 */
#[Fillable(['store_id', 'name', 'rule', 'vip_at', 'milestones'])]
class CustomerContextFilter extends Model
{
    /** @use HasFactory<CustomerContextFilterFactory> */
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return ['milestones' => 'array', 'vip_at' => 'integer'];
    }
}
