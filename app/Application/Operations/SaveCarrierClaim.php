<?php

namespace App\Application\Operations;

use App\Domain\Orders\CarrierClaimLedger;
use App\IssuePriority;
use App\Models\CarrierClaim;
use App\Models\CarrierClaimPayment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCarrierClaim
{
    public const ISSUE_SOURCE = 'carrier_claim';

    public const LEDGER_CREDIT_TYPES = ['label_refund', 'adjustment_credit'];

    public function __construct(private readonly RaiseOperationalIssue $issues) {}

    /**
     * Creates or updates a claim. A second case for the same provider and external reference is refused, not merged.
     *
     * @param  array<string, mixed>  $attributes  Validated input; evidence is a list of https URLs.
     */
    public function handle(Store $store, ?CarrierClaim $claim, array $attributes, User $user): CarrierClaim
    {
        $attributes['currency'] = mb_strtoupper(trim((string) $attributes['currency']));
        $attributes['case_key'] = CarrierClaim::caseKey((string) $attributes['provider'], (string) $attributes['external_reference']);
        $attributes['dates_verified_at'] = ($attributes['dates_verified'] ?? false) ? now() : null;
        $attributes['dates_verified_by'] = ($attributes['dates_verified'] ?? false) ? $user->getKey() : null;
        unset($attributes['dates_verified']);

        if ($claim !== null && $claim->payments()->where('currency', '!=', $attributes['currency'])->exists()) {
            throw ValidationException::withMessages(['currency' => __('Payments in another currency are already recorded; the currency cannot change.')]);
        }
        if ($claim === null && $store->carrierClaims()->where('case_key', $attributes['case_key'])->exists()) {
            throw $this->duplicate();
        }

        try {
            $claim = DB::transaction(function () use ($store, $claim, $attributes): CarrierClaim {
                if ($claim === null) {
                    return $store->carrierClaims()->create($attributes);
                }
                $claim->update($attributes);

                return $claim;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate();
        }
        $this->syncFollowUp($store, $claim);

        return $claim;
    }

    /**
     * Records money actually received. A ledger reference already used in this store cannot be counted twice.
     *
     * @param  array{kind: string, amount: string, currency: string, received_on: string, source_note: string, ledger_reference?: string|null}  $payment
     */
    public function recordPayment(Store $store, CarrierClaim $claim, array $payment): CarrierClaimPayment
    {
        $payment['currency'] = mb_strtoupper(trim($payment['currency']));
        $payment['ledger_reference'] = ($payment['ledger_reference'] ?? '') === '' ? null : $payment['ledger_reference'];
        if ($payment['currency'] !== $claim->currency) {
            throw ValidationException::withMessages(['currency' => __('The payment currency must match the claim currency.')]);
        }
        if ($payment['ledger_reference'] !== null) {
            $this->assertMatchesLedger($store, $claim, $payment);
        }
        try {
            return $claim->payments()->create($payment + ['store_id' => $store->getKey()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['ledger_reference' => __('This ledger posting is already recorded.')]);
        }
    }

    /**
     * Posted credits in the imported billing ledger that could be this claim's postage reimbursement and are not yet counted.
     *
     * @return list<array{transaction_id: string, amount: string, currency: string, posted_at: string}>
     */
    public function ledgerCandidates(Store $store, CarrierClaim $claim): array
    {
        $linked = CarrierClaimPayment::query()->where('store_id', $store->getKey())->whereNotNull('ledger_reference')->pluck('ledger_reference')->all();

        $candidates = $store->shippingBillingEntries()
            ->whereIn('type', self::LEDGER_CREDIT_TYPES)->where('currency', $claim->currency)
            ->where(fn ($query) => $query->when($claim->order_number, fn ($q, $number) => $q->orWhere('order_number', ltrim($number, '#')))->when($claim->tracking_number, fn ($q, $number) => $q->orWhere('tracking_number', $number)))
            ->whereNotIn('transaction_id', $linked)->orderByDesc('posted_at')->limit(20)
            ->get(['transaction_id', 'amount', 'currency', 'posted_at'])
            ->map(fn ($entry): array => ['transaction_id' => $entry->transaction_id, 'amount' => (string) $entry->amount, 'currency' => $entry->currency, 'posted_at' => $entry->posted_at->toDateString()])->all();

        return array_values($candidates);
    }

    /** @param array<string, mixed> $payment */
    private function assertMatchesLedger(Store $store, CarrierClaim $claim, array $payment): void
    {
        $entry = $store->shippingBillingEntries()->where('transaction_id', $payment['ledger_reference'])->whereIn('type', self::LEDGER_CREDIT_TYPES)->first();
        $problem = match (true) {
            $entry === null => __('No imported billing credit with this transaction ID was found.'),
            $payment['kind'] !== 'postage' => __('Only postage reimbursements can be linked to the billing ledger.'),
            $entry->currency !== $claim->currency => __('The billing credit uses another currency.'),
            CarrierClaimLedger::toMicros((string) $entry->amount) !== CarrierClaimLedger::toMicros((string) $payment['amount']) => __('The amount must equal the billing credit.'),
            default => null,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages(['ledger_reference' => $problem]);
        }
    }

    public function addCommunication(CarrierClaim $claim, string $text, User $user): void
    {
        DB::transaction(function () use ($claim, $text, $user): void {
            $locked = CarrierClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
            $locked->update(['communications' => [...($locked->communications ?? []), ['on' => now()->toDateString(), 'by' => $user->name, 'text' => $text]]]);
        });
    }

    /** Keeps one operational issue in step with the claim's follow-up date; closed claims resolve it. */
    public function syncFollowUp(Store $store, CarrierClaim $claim): void
    {
        $fingerprint = RaiseOperationalIssue::fingerprint(self::ISSUE_SOURCE, (string) $claim->getKey());
        if ($claim->isActive() && $claim->follow_up_on !== null) {
            $this->issues->handle($store, [
                'source_tool' => self::ISSUE_SOURCE,
                'fingerprint' => $fingerprint,
                'title' => 'Carrier claim follow-up: '.$claim->provider.' '.$claim->external_reference,
                'priority' => $claim->follow_up_on->isPast() && ! $claim->follow_up_on->isToday() ? IssuePriority::High : IssuePriority::Normal,
                'reference' => $claim->external_reference,
                'payload' => ['claim_id' => $claim->getKey(), 'provider' => $claim->provider],
                'due_date' => $claim->follow_up_on,
            ], reopenIgnored: false);

            return;
        }
        $issue = $store->operationalIssues()->where('fingerprint', $fingerprint)->first();
        if ($issue !== null) {
            $this->issues->resolve($issue);
        }
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages(['external_reference' => __('A claim with this provider and reference already exists.')]);
    }
}
