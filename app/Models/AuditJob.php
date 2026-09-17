<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'status', 'start_date', 'end_date', 'started_at', 'finished_at', 'error_category'])]
class AuditJob extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
