<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

class ReturnExceptionAnalyzer
{
    /** @param list<array<string, mixed>> $returns
     * @param array{approval_days: int, processing_days: int, exchange_days: int} $policy
     * @return list<array<string, mixed>> */
    public function analyze(array $returns, array $policy, CarbonImmutable $now): array
    {
        $rows = [];
        foreach ($returns as $return) {
            $status = $return['status'];
            if (in_array($status, ['CANCELED', 'DECLINED'], true)) {
                continue;
            }
            $created = CarbonImmutable::parse($return['createdAt'])->setTimezone($now->timezone);
            if ($status === 'REQUESTED') {
                $row = $this->overdue($return, 'approval_overdue', 'Review return request', $created, $policy['approval_days'], 0, $now);
                if ($row !== null) {
                    $rows[] = $row;
                }

                continue;
            }
            $receipts = [];
            $seenDispositions = [];
            foreach ($return['reverse_lines'] as $line) {
                $key = $line['fulfillmentLineItem']['id'] ?? null;
                if ($key === null) {
                    continue;
                }
                foreach ($line['dispositions'] as $disposition) {
                    if (! is_string($disposition['id'] ?? null) || isset($seenDispositions[$disposition['id']]) || ! in_array($disposition['type'], ['PROCESSING_REQUIRED', 'RESTOCKED', 'NOT_RESTOCKED'], true)
                        || empty($disposition['location']['id']) || (int) $disposition['quantity'] < 1) {
                        continue;
                    }
                    $date = CarbonImmutable::parse($disposition['createdAt'])->setTimezone($now->timezone);
                    if ($date->gt($now)) {
                        continue;
                    }
                    $seenDispositions[$disposition['id']] = true;
                    $receipts[$key] ??= ['quantity' => 0, 'at' => $date];
                    $receipts[$key]['quantity'] += (int) $disposition['quantity'];
                    $receipts[$key]['at'] = $date->gt($receipts[$key]['at']) ? $date : $receipts[$key]['at'];
                }
            }
            $receivedAt = null;
            $unprocessed = 0;
            $processingStarted = null;
            foreach ($return['return_lines'] as $line) {
                $receipt = $receipts[$line['fulfillmentLineItem']['id'] ?? ''] ?? null;
                if ($receipt === null) {
                    continue;
                }
                $pending = max(0, min((int) $line['quantity'], $receipt['quantity']) - (int) $line['processedQuantity']);
                $pending = min($pending, (int) $line['unprocessedQuantity']);
                if ($status === 'OPEN' && $pending > 0 && $now->gt($receipt['at']->addWeekdays($policy['processing_days']))) {
                    $unprocessed += $pending;
                    $processingStarted = $processingStarted === null || $receipt['at']->lt($processingStarted) ? $receipt['at'] : $processingStarted;
                }
                $receivedAt = $receivedAt === null || $receipt['at']->gt($receivedAt) ? $receipt['at'] : $receivedAt;
            }
            if ($unprocessed > 0 && $processingStarted !== null) {
                $row = $this->overdue($return, 'processing_overdue', 'Process received return items', $processingStarted, $policy['processing_days'], $unprocessed, $now);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            $pendingShipping = 0;
            $pendingExchangeProcessing = 0;
            $seenLines = [];
            foreach ($return['exchange_lines'] as $exchange) {
                if ($status === 'OPEN' && $receivedAt !== null) {
                    $pendingExchangeProcessing += (int) $exchange['unprocessedQuantity'];
                }
                foreach ($exchange['lineItems'] ?? [] as $line) {
                    if (($line['requiresShipping'] ?? false) && ! isset($seenLines[$line['id']])) {
                        $pendingShipping += max(0, min((int) $line['unfulfilledQuantity'], (int) $line['currentQuantity']));
                        $seenLines[$line['id']] = true;
                    }
                }
            }
            $approved = empty($return['requestApprovedAt']) ? null : CarbonImmutable::parse($return['requestApprovedAt'])->setTimezone($now->timezone);
            $exchangeStart = $receivedAt ?? $approved ?? $created;
            if ($approved !== null && $approved->gt($exchangeStart)) {
                $exchangeStart = $approved;
            }
            if ($pendingShipping > 0 || $pendingExchangeProcessing > 0) {
                $row = $this->overdue($return, 'exchange_overdue', $pendingShipping > 0 ? 'Fulfill exchange items' : 'Process exchange after receipt', $exchangeStart, $policy['exchange_days'], max($pendingShipping, $pendingExchangeProcessing), $now);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }
        usort($rows, fn (array $left, array $right): int => strcmp($left['due_at'], $right['due_at']) ?: strcmp($left['return_id'], $right['return_id']));

        return $rows;
    }

    /** @param array<string, mixed> $return
     * @return array<string, mixed>|null */
    private function overdue(array $return, string $kind, string $nextAction, CarbonImmutable $started, int $days, int $quantity, CarbonImmutable $now): ?array
    {
        $due = $started->addWeekdays($days);
        if ($now->lte($due)) {
            return null;
        }

        return [
            'return_id' => $return['id'], 'return_name' => $return['name'], 'return_status' => $return['status'],
            'shopify_id' => (string) $return['order']['legacyResourceId'], 'order_number' => $return['order']['name'],
            'kind' => $kind, 'next_action' => $nextAction, 'quantity' => $quantity,
            'started_at' => $started->toIso8601String(), 'due_at' => $due->toIso8601String(),
            'business_days' => $days, 'receipt_confirmed' => $kind === 'processing_overdue' || $nextAction === 'Process exchange after receipt',
        ];
    }
}
