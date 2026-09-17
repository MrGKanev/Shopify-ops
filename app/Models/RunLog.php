<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'tool', 'status', 'start_date', 'end_date', 'duration_seconds', 'scanned', 'rows_found', 'error', 'meta'])]
class RunLog extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'duration_seconds' => 'float', 'meta' => 'array'];
    }
}
