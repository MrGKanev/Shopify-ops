<?php

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class ShippingBillingAnalyzer
{
    /** @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>> */
    public function analyze(array $entries, CarbonImmutable $now): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            if (($entry['excluded'] ?? false) === true) {
                continue;
            }
            $key = $entry['source_account'].'|'.$entry['shipment_id'].'|'.$entry['currency'];
            $groups[$key][] = $entry;
        }
        $rows = [];
        foreach ($groups as $key => $postings) {
            $totals = array_fill_keys(['label_charge', 'insurance_charge', 'adjustment_debit', 'adjustment_credit', 'label_refund', 'label_refund_reversal'], BigDecimal::zero());
            $base = 0;
            $expected = null;
            $due = null;
            foreach ($postings as $entry) {
                $totals[$entry['type']] = $totals[$entry['type']]->plus($entry['amount']);
                if ($entry['type'] === 'label_charge') {
                    $base++;
                    if ($entry['expected_refund'] !== null) {
                        $expected = BigDecimal::of($entry['expected_refund']);
                        $due = $entry['refund_due_at'] === null ? null : substr($entry['refund_due_at'], 0, 10);
                    }
                }
            }
            $ids = array_unique(array_column($postings, 'order_id'));
            $numbers = array_unique(array_column($postings, 'order_number'));
            $identityConflict = count($ids) !== 1 || count($numbers) !== 1;
            if ($base !== 1 || $identityConflict) {
                $expected = $due = null;
            }
            $refunds = $totals['label_refund']->minus($totals['label_refund_reversal']);
            $charges = $totals['label_charge']->plus($totals['insurance_charge']);
            $net = $charges->plus($totals['adjustment_debit'])->minus($totals['adjustment_credit'])->minus($refunds);
            $outstanding = $expected === null ? null : $expected->minus($refunds);
            if ($outstanding?->isLessThan(0)) {
                $outstanding = BigDecimal::zero();
            }
            $refundStatus = match (true) {
                $identityConflict || $base > 1 || $refunds->isLessThan(0) => 'review_required',
                $expected === null => 'expectation_not_set',
                $expected->isZero() && $refunds->isZero() => 'no_refund_due',
                $outstanding?->isZero() === true => 'credited',
                $due !== null && $due < $now->toDateString() => 'overdue',
                $refunds->isGreaterThan(0) => 'partially_credited',
                default => 'awaiting_credit',
            };
            $first = $postings[0];
            $row = ['key' => $key, 'shipment_id' => $first['shipment_id'], 'order_id' => $first['order_id'], 'order_number' => $first['order_number'], 'source_account' => $first['source_account'], 'currency' => $first['currency'], 'charges' => (string) $charges, 'adjustments' => (string) $totals['adjustment_debit'], 'credits' => (string) $totals['adjustment_credit'], 'refunds' => (string) $refunds, 'observed_net' => (string) $net, 'billed_cost' => $base === 1 && ! $identityConflict ? (string) $net : null, 'base_count' => $base, 'identity_conflict' => $identityConflict, 'expected_refund' => $expected === null ? null : (string) $expected, 'refund_due_at' => $due, 'outstanding_refund' => $outstanding === null ? null : (string) $outstanding, 'refund_status' => $refundStatus];
            foreach (['carrier', 'service', 'package', 'skus', 'tracking_number'] as $field) {
                $row[$field] = implode('; ', array_values(array_unique(array_filter(array_column($postings, $field), fn (mixed $value): bool => is_string($value) && $value !== ''))));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>> */
    public function grouped(array $groups, string $dimension): array
    {
        $rows = [];
        foreach ($groups as $group) {
            $label = $group[$dimension] ?: '';
            $key = $label.'|'.$group['currency'];
            $rows[$key] ??= ['label' => $label, 'currency' => $group['currency'], 'labels' => 0, 'net' => '0', 'adjustments' => '0', 'credits' => '0', 'refunds' => '0'];
            $rows[$key]['labels']++;
            foreach (['net' => 'observed_net', 'adjustments' => 'adjustments', 'credits' => 'credits', 'refunds' => 'refunds'] as $column => $field) {
                $rows[$key][$column] = (string) BigDecimal::of($rows[$key][$column])->plus($group[$field]);
            }
        }

        return array_values($rows);
    }
}
