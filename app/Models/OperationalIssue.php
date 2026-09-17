<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\OperationalIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'owner_user_id', 'source_tool', 'fingerprint', 'title', 'reference', 'status', 'priority', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at', 'due_date', 'resolution_note', 'payload'])]
class OperationalIssue extends Model
{
    /** @use HasFactory<OperationalIssueFactory> */
    use BelongsToStore, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'due_date' => 'date',
            'payload' => 'array',
        ];
    }
}
