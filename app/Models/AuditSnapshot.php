<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'tool', 'report_date', 'start_date', 'end_date', 'rows_found', 'result'])]
class AuditSnapshot extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['report_date' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'result' => 'array'];
    }
}
