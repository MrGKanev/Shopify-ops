<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'webhook_id', 'topic', 'shop_domain', 'api_version', 'subject_id', 'status', 'payload', 'occurred_at', 'processed_at', 'error_category'])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
