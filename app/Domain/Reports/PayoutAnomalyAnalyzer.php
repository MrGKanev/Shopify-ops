<?php

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Throwable;

class PayoutAnomalyAnalyzer
{
    private const array COMPONENT_TYPES = [
        'CHARGE', 'REFUND', 'ADJUSTMENT', 'CHARGE_ADJUSTMENT', 'REFUND_ADJUSTMENT', 'REFUND_FAILURE',
        'DISPUTE_WITHDRAWAL', 'DISPUTE_REVERSAL', 'CHARGEBACK_HOLD', 'CHARGEBACK_HOLD_RELEASE',
        'CHARGEBACK_FEE', 'CHARGEBACK_FEE_REFUND', 'RESERVED_FUNDS', 'RESERVED_FUNDS_REVERSAL',
        'RESERVED_FUNDS_WITHDRAWAL', 'RISK_WITHDRAWAL', 'RISK_REVERSAL', 'STRIPE_FEE',
        'SHOPIFY_COLLECTIVE_CREDIT', 'SHOPIFY_COLLECTIVE_DEBIT', 'SHOPIFY_COLLECTIVE_CREDIT_REVERSAL',
        'SHOPIFY_COLLECTIVE_DEBIT_REVERSAL', 'TAX_ADJUSTMENT_CREDIT', 'TAX_ADJUSTMENT_DEBIT',
        'TAX_ADJUSTMENT_CREDIT_REVERSAL', 'TAX_ADJUSTMENT_DEBIT_REVERSAL',
    ];

    public function __construct(private readonly ExactDecimalAmount $amounts) {}

    /** @param array<string, mixed> $data
     * @param array<string, mixed> $policy
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>, transactions: list<array<string, mixed>>} */
    public function analyze(array $data, array $policy, CarbonImmutable $asOf): array
    {
        $payout = $data['payout'];
        $currency = (string) data_get($payout, 'net.currencyCode', '');
        $net = preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $this->amounts->money($payout['net'] ?? null, $currency) : null;
        $complete = $data['complete'] && $net !== null;
        $notes = $data['notes'];
        $rows = [];
        $details = [];
        $total = BigDecimal::of('0');
        $fees = BigDecimal::of('0');
        $count = 0;
        $seen = [];
        $status = $payout['status'] ?? '';
        if (in_array($status, ['FAILED', 'CANCELED'], true)) {
            $rows[] = $this->finding('payout_status', 'Payout is failed or canceled according to Shopify.', (string) $payout['legacyResourceId']);
        }
        try {
            $issued = is_string($payout['issuedAt'] ?? null) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $payout['issuedAt']) === 1 ? CarbonImmutable::parse($payout['issuedAt']) : null;
            if ($issued !== null && $issued->format('Y-m-d') !== substr($payout['issuedAt'], 0, 10)) {
                $issued = null;
            }
        } catch (Throwable) {
            $issued = null;
        }
        if ($issued === null || ! in_array($status, ['PAID', 'FAILED', 'CANCELED', 'SCHEDULED', 'IN_TRANSIT'], true)) {
            $complete = false;
            $notes[] = 'Payout issue date or status is unconfirmed.';
        }
        if ($issued !== null && in_array($status, ['SCHEDULED', 'IN_TRANSIT'], true) && $issued->lt($asOf->subDays((int) $policy['max_age_days']))) {
            $rows[] = $this->finding('pending_age', 'Pending payout age exceeds the chosen calendar-day limit since issue date.', (string) $payout['legacyResourceId']);
        }
        $threshold = $this->amounts->parse($policy['adjustment_threshold'] ?? null);
        $tolerance = $this->amounts->parse($policy['tolerance']);
        if ($tolerance === null || ! in_array($payout['transactionType'] ?? null, ['DEPOSIT', 'WITHDRAWAL'], true)) {
            $complete = false;
            $notes[] = 'Comparison tolerance or payout direction is unconfirmed.';
        }
        foreach ($data['transactions'] as $transaction) {
            $id = (string) ($transaction['id'] ?? '');
            $type = (string) ($transaction['type'] ?? '');
            $amount = $this->amounts->money($transaction['amount'] ?? null, $currency);
            $fee = $this->amounts->money($transaction['fee'] ?? null, $currency);
            $transactionNet = $this->amounts->money($transaction['net'] ?? null, $currency);
            $details[] = [
                'id' => $id, 'type' => $type, 'date' => $transaction['transactionDate'] ?? '', 'source_type' => $transaction['sourceType'] ?? '',
                'order_id' => data_get($transaction, 'associatedOrder.id'), 'order_name' => data_get($transaction, 'associatedOrder.name'),
                'order_transaction_id' => $transaction['sourceOrderTransactionId'] ?? null, 'adjustment_reason' => $transaction['adjustmentReason'] ?? '',
                'amount' => $transaction['amount'] ?? null, 'fee' => $transaction['fee'] ?? null, 'net' => $transaction['net'] ?? null,
                'adjustment_orders' => $transaction['adjustmentsOrders'] ?? [],
            ];
            if ($id === '' || isset($seen[$id]) || data_get($transaction, 'associatedPayout.id') !== $payout['id'] || ($transaction['test'] ?? null) !== false) {
                $complete = false;
                $notes[] = 'Duplicate, test or unowned transactions prevent a confirmed comparison.';

                continue;
            }
            $seen[$id] = true;
            if ($type === 'TRANSFER') {
                $notes[] = 'Payout transfer ledger rows are excluded from the component sum to avoid double counting.';

                continue;
            }
            if (! in_array($type, self::COMPONENT_TYPES, true)) {
                $complete = false;
                $notes[] = 'An unsupported transaction type prevents a confirmed comparison.';
            }
            if ($amount === null || $fee === null || $transactionNet === null) {
                $complete = false;
                $notes[] = 'Missing, invalid or mixed-currency amounts prevent a confirmed comparison.';

                continue;
            }
            $total = $total->plus($transactionNet);
            $fees = $fees->plus($fee);
            $count++;
            if ($threshold !== null && (($transaction['sourceType'] ?? '') === 'ADJUSTMENT' || str_contains($type, 'ADJUSTMENT')) && $amount->abs()->isGreaterThan($threshold)) {
                $rows[] = $this->finding('large_adjustment', 'Adjustment amount exceeds the chosen threshold in payout currency.', $id);
            }
        }
        if ($count === 0) {
            $complete = false;
            $notes[] = 'No confirmed payout component transactions were found.';
        }
        $finalDeposit = $status === 'PAID' && ($payout['transactionType'] ?? null) === 'DEPOSIT';
        if (! $finalDeposit) {
            $notes[] = 'Total comparison is limited to finalized deposit payouts; this payout is not eligible.';
        }
        $delta = $complete && $finalDeposit && $net !== null && $tolerance !== null ? $total->minus($net) : null;
        if ($delta !== null && $delta->abs()->isGreaterThan($tolerance)) {
            $rows[] = $this->finding('net_difference', 'Included transaction net differs from payout net beyond the chosen tolerance.', (string) $payout['legacyResourceId']);
        }

        return ['rows' => $rows, 'transactions' => $details, 'summary' => [
            'payout_id' => $payout['legacyResourceId'], 'status' => $status, 'direction' => $payout['transactionType'] ?? '', 'issued_at' => $payout['issuedAt'] ?? '',
            'currency' => $currency, 'payout_net' => $net === null ? null : (string) $net,
            'observed_component_net' => $count > 0 ? (string) $total : null, 'observed_fees' => $count > 0 ? (string) $fees : null,
            'confirmed_component_net' => $complete ? (string) $total : null, 'delta' => $delta === null ? null : (string) $delta,
            'complete' => $complete, 'comparison_eligible' => $finalDeposit, 'notes' => array_values(array_unique($notes)),
            'checked_at' => $asOf->toIso8601String(), 'policy' => $policy,
        ]];
    }

    /** @return array{code: string, message: string, reference: string} */
    private function finding(string $code, string $message, string $reference): array
    {
        return compact('code', 'message', 'reference');
    }
}
