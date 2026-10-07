<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

class OperationalDigestAnalyzer
{
    /** @param list<array<string, mixed>> $orders
     * @param list<array<string, mixed>> $shipStationOrders
     * @param list<array<string, mixed>> $issues
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>} */
    public function analyze(array $orders, array $shipStationOrders, array $issues, int $slaDays, CarbonImmutable $now): array
    {
        $counts = ['paid_pending' => 0, 'sync_findings' => 0, 'sla_overdue' => 0, 'sla_due_soon' => 0, 'open_issues' => count($issues)];
        $rows = [];
        $ssIndex = [];
        foreach ($shipStationOrders as $ss) {
            $ssIndex[$this->number($ss['orderNumber'] ?? '')][] = $ss;
        }
        foreach ($orders as $order) {
            $number = $this->number($order['name'] ?? '');
            $matches = $ssIndex[$number] ?? [];
            $pending = empty($order['cancelled_at']) && in_array($order['financial_status'] ?? '', ['paid', 'partially_refunded'], true) && ($order['fulfillment_status'] ?? '') !== 'fulfilled';
            if ($pending) {
                $counts['paid_pending']++;
                $rows[] = $this->row('paid_pending', $order, 'Paid order awaiting Shopify fulfillment');
                $created = CarbonImmutable::parse($order['created_at'])->setTimezone($now->timezone);
                $due = $created->addDays($slaDays);
                if ($due->lte($now)) {
                    $counts['sla_overdue']++;
                    $rows[] = $this->row('sla_overdue', $order, 'Estimated fulfillment SLA overdue', $due->toIso8601String());
                } elseif ($due->lte($now->addHours(24))) {
                    $counts['sla_due_soon']++;
                    $rows[] = $this->row('sla_due_soon', $order, 'Estimated fulfillment SLA due within 24 hours', $due->toIso8601String());
                }
            }
            if (count($matches) > 1) {
                $counts['sync_findings']++;
                $rows[] = $this->row('sync_review', $order, 'Multiple ShipStation orders match; review split or duplicate records');

                continue;
            }
            $ss = $matches[0] ?? null;
            if ($ss === null) {
                continue;
            }
            if (($ss['orderStatus'] ?? '') === 'shipped' && $pending) {
                $counts['sync_findings']++;
                $rows[] = $this->row('sync_shipped_pending', $order, 'ShipStation status shipped; Shopify fulfillment pending');
            }
            if (($ss['orderStatus'] ?? '') === 'cancelled' && $pending) {
                $counts['sync_findings']++;
                $rows[] = $this->row('sync_paid_cancelled', $order, 'Paid Shopify order awaits fulfillment but ShipStation is cancelled');
            }
            if (($order['fulfillment_status'] ?? '') === 'fulfilled' && in_array($ss['orderStatus'] ?? '', ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true)) {
                $counts['sync_findings']++;
                $rows[] = $this->row('sync_fulfilled_active', $order, 'Shopify is fulfilled but the ShipStation order remains active');
            }
            if ((! empty($order['cancelled_at']) || ($order['financial_status'] ?? '') === 'refunded') && in_array($ss['orderStatus'] ?? '', ['awaiting_payment', 'awaiting_shipment', 'on_hold'], true)) {
                $counts['sync_findings']++;
                $rows[] = $this->row('sync_active_conflict', $order, 'Cancelled or refunded Shopify order remains active in ShipStation');
            }
        }
        foreach ($issues as $issue) {
            $rows[] = ['kind' => 'open_issue', 'reference' => (string) $issue['id'], 'order_number' => $issue['order_number'] ?? '', 'description' => $issue['title'], 'due_at' => $issue['due_at'] ?? null, 'issue_id' => $issue['id'], 'priority' => $issue['priority']];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['kind'], $b['kind']) ?: strcmp((string) ($a['due_at'] ?? ''), (string) ($b['due_at'] ?? '')) ?: strcmp($a['reference'], $b['reference']));

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** @param array<string, mixed> $order
     * @return array<string, mixed> */
    private function row(string $kind, array $order, string $description, ?string $due = null): array
    {
        return ['kind' => $kind, 'reference' => (string) $order['id'], 'order_number' => $order['name'], 'description' => $description, 'due_at' => $due];
    }

    private function number(mixed $number): string
    {
        return is_scalar($number) ? mb_strtolower(ltrim(trim((string) $number), '#')) : '';
    }
}
