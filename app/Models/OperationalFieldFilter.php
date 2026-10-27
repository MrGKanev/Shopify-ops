<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\OperationalFieldFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved per-store worklist filter on one operational field: orders where it is missing, unreadable, present or contains text.
 *
 * @property OperationalField $field
 * @property int $operational_field_id
 * @property string $name
 * @property string $condition
 * @property string|null $text
 */
#[Fillable(['store_id', 'operational_field_id', 'name', 'condition', 'text'])]
class OperationalFieldFilter extends Model
{
    /** @use HasFactory<OperationalFieldFilterFactory> */
    use BelongsToStore, HasFactory;

    public const CONDITIONS = ['missing', 'unreadable', 'present', 'contains'];

    /** @return BelongsTo<OperationalField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(OperationalField::class, 'operational_field_id');
    }
}
