<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\ShipStationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'event_key', 'generation', 'topic', 'payload', 'status', 'available_at', 'processed_at', 'error_category'])]
class ShipStationEvent extends Model
{
    /** @use HasFactory<ShipStationEventFactory> */
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'available_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
