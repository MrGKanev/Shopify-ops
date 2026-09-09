<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'tool', 'status', 'start_date', 'end_date', 'duration_seconds', 'scanned', 'rows_found', 'error', 'meta'])]
class RunLog extends Model
{
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'duration_seconds' => 'float', 'meta' => 'array'];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
