<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\RateQuoteSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'user_id', 'order_number', 'shipstation_order_id', 'status', 'mode', 'input', 'context', 'quotes', 'signature', 'quoted_at', 'selected_at', 'selected_service', 'message', 'error_category'])]
#[Hidden(['input', 'context', 'quotes', 'signature'])]
class RateQuoteSnapshot extends Model
{
    /** @use HasFactory<RateQuoteSnapshotFactory> */
    use BelongsToStore, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<array<string, mixed>> */
    public function quoteRows(): array
    {
        $rows = [];
        foreach ($this->quotes ?? [] as $quote) {
            if (is_array($quote)) {
                $rows[] = $quote;
            }
        }

        return $rows;
    }

    protected function casts(): array
    {
        return ['input' => 'encrypted:array', 'context' => 'encrypted:array', 'quotes' => 'encrypted:array', 'quoted_at' => 'datetime', 'selected_at' => 'datetime'];
    }
}
