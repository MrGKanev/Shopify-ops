<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\PickListTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string> $columns
 */
#[Fillable(['store_id', 'name', 'columns', 'sort', 'size'])]
class PickListTemplate extends Model
{
    /** @use HasFactory<PickListTemplateFactory> */
    use BelongsToStore, HasFactory;

    /** Text and image size presets; the view maps each to fixed classes, never to a free-form value. */
    public const SIZES = ['normal', 'large', 'xlarge'];

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
