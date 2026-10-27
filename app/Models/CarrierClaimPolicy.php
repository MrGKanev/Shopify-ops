<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\CarrierClaimPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A claim-deadline rule that staff read from the provider's own terms. The app never infers a claim window.
 */
#[Fillable(['store_id', 'provider', 'account', 'service', 'description', 'source_url', 'verified_on', 'verified_by'])]
class CarrierClaimPolicy extends Model
{
    /** @use HasFactory<CarrierClaimPolicyFactory> */
    use BelongsToStore, HasFactory;

    public function label(): string
    {
        return trim($this->provider.' '.($this->account ?? '').' '.($this->service ?? '')).': '.$this->description;
    }

    protected function casts(): array
    {
        return ['verified_on' => 'date'];
    }
}
