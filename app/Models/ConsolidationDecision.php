<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\ConsolidationDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An operator's manual decision for one open order in the consolidation review: keep it out of every candidate group, or mark its review flags (paid shipping, customs, insurance...) as checked.
 *
 * @property string $order_number
 * @property string $decision
 */
#[Fillable(['store_id', 'order_number', 'decision', 'user_id'])]
class ConsolidationDecision extends Model
{
    /** @use HasFactory<ConsolidationDecisionFactory> */
    use BelongsToStore, HasFactory;

    public const DECISIONS = ['excluded', 'reviewed'];
}
