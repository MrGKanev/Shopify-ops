<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\RemediationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'user_id', 'group_uuid', 'batch_id', 'order_number', 'shopify_id', 'action', 'status', 'plan', 'completed_steps', 'result_message', 'error_category', 'expires_at', 'finished_at'])]
#[Hidden(['plan'])]
class RemediationRun extends Model
{
    /** @use HasFactory<RemediationRunFactory> */
    use BelongsToStore, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['plan' => 'encrypted:array', 'completed_steps' => 'integer', 'expires_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
