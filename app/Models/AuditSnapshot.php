<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'tool', 'report_date', 'start_date', 'end_date', 'rows_found', 'result'])]
class AuditSnapshot extends Model
{
    protected function casts(): array
    {
        return ['report_date' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'result' => 'array'];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
