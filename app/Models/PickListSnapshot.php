<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\PickListSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A pick list as it was generated: lines, totals, allocations and the template settings of that moment, so it can be reprinted without asking ShipStation again.
 *
 * @property list<string> $columns
 * @property array{lines: list<array{sku: string, name: string, options: string, location: string, image: string, quantity: int, allocations: list<array{order: string, quantity: int}>}>, totalQuantity: int, warnings: list<string>, orderCount: int, missing: list<string>, ambiguous: list<string>} $payload
 */
#[Fillable(['store_id', 'created_by', 'template_name', 'columns', 'sort', 'size', 'payload'])]
class PickListSnapshot extends Model
{
    /** @use HasFactory<PickListSnapshotFactory> */
    use BelongsToStore, HasFactory;

    public const MAX_PER_STORE = 50;

    protected function casts(): array
    {
        return ['columns' => 'array', 'payload' => 'array'];
    }
}
