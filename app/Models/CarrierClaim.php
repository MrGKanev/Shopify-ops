<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\CarrierClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Internal register entry for a loss/damage claim filed with a carrier or insurer. Dates and amounts are entered by staff.
 *
 * @property list<string>|null $evidence
 * @property list<array{on: string, by: string, text: string}>|null $communications
 */
#[Fillable(['store_id', 'operational_issue_id', 'provider', 'account', 'external_reference', 'case_key', 'order_number', 'tracking_number', 'status', 'currency', 'goods_requested', 'goods_approved', 'postage_requested', 'postage_approved', 'submitted_on', 'decision_on', 'follow_up_on', 'deadline_on', 'deadline_policy', 'carrier_claim_policy_id', 'dates_verified_at', 'dates_verified_by', 'evidence', 'communications'])]
class CarrierClaim extends Model
{
    /** @use HasFactory<CarrierClaimFactory> */
    use BelongsToStore, HasFactory;

    public const STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'denied', 'paid', 'closed'];

    public const ACTIVE_STATUSES = ['draft', 'submitted', 'under_review', 'approved'];

    /** Evidence files are removed with the claim; a database cascade from the store is handled by Store. */
    protected static function booted(): void
    {
        static::deleted(function (CarrierClaim $claim): void {
            Storage::disk(CarrierClaimDocument::DISK)->deleteDirectory(CarrierClaimDocument::directory($claim->store_id, $claim->getKey()));
        });
    }

    public static function caseKey(string $provider, string $reference): string
    {
        return mb_strtolower(trim($provider)).'|'.mb_strtolower(trim($reference));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** @return HasMany<CarrierClaimPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CarrierClaimPayment::class);
    }

    /** @return HasMany<CarrierClaimDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CarrierClaimDocument::class);
    }

    /** @return BelongsTo<OperationalIssue, $this> */
    public function operationalIssue(): BelongsTo
    {
        return $this->belongsTo(OperationalIssue::class);
    }

    protected function casts(): array
    {
        return [
            'goods_requested' => 'decimal:6',
            'goods_approved' => 'decimal:6',
            'postage_requested' => 'decimal:6',
            'postage_approved' => 'decimal:6',
            'submitted_on' => 'date',
            'decision_on' => 'date',
            'follow_up_on' => 'date',
            'deadline_on' => 'date',
            'dates_verified_at' => 'datetime',
            'evidence' => 'array',
            'communications' => 'array',
        ];
    }
}
