<?php

namespace App\Domain\Reports;

class OnHoldStallAnalyzer
{
    /** @param list<array<string, mixed>> $nodes @return list<array<string, mixed>> */
    public function analyze(array $nodes, int $now): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            $order = is_array($node['order'] ?? null) ? $node['order'] : [];
            $createdAt = $this->text($order['createdAt'] ?? '');
            $createdTimestamp = strtotime($createdAt);
            $holds = is_array($node['fulfillmentHolds'] ?? null) ? $node['fulfillmentHolds'] : [];
            $hold = is_array($holds[0] ?? null) ? $holds[0] : [];
            $rows[] = [
                'shopify_id' => $this->text($order['legacyResourceId'] ?? ''), 'order_number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($createdAt, 0, 10), 'days_waiting' => $createdTimestamp === false ? 0 : (int) floor(($now - $createdTimestamp) / 86400),
                'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['totalPriceSet']['shopMoney']['amount'] ?? null) ? (float) $order['totalPriceSet']['shopMoney']['amount'] : 0.0,
                'financial' => $this->text($order['displayFinancialStatus'] ?? ''), 'fulfillment' => $this->text($order['displayFulfillmentStatus'] ?? ''),
                'hold_reason' => $this->text($hold['reason'] ?? ''), 'hold_notes' => $this->text($hold['reasonNotes'] ?? ''),
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['days_waiting'] <=> $a['days_waiting']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
