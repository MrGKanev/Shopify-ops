<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\OperationalFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-store name for a value read from a Shopify order metafield or a ShipStation custom field.
 *
 * @property string $alias
 * @property string $source_type
 * @property string $source
 * @property bool $required
 */
#[Fillable(['store_id', 'alias', 'source_type', 'source', 'required'])]
class OperationalField extends Model
{
    /** @use HasFactory<OperationalFieldFactory> */
    use BelongsToStore, HasFactory;

    public const SOURCE_METAFIELD = 'metafield';

    public const SOURCE_CUSTOM_FIELD = 'ss_custom';

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }
}
