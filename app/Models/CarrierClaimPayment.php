<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\CarrierClaimPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'carrier_claim_id', 'kind', 'amount', 'currency', 'received_on', 'source_note', 'ledger_reference'])]
class CarrierClaimPayment extends Model
{
    /** @use HasFactory<CarrierClaimPaymentFactory> */
    use BelongsToStore, HasFactory;

    public const KINDS = ['goods', 'postage'];

    /** @return BelongsTo<CarrierClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(CarrierClaim::class, 'carrier_claim_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'received_on' => 'date'];
    }
}
