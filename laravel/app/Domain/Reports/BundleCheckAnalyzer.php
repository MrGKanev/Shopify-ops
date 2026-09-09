<?php

namespace App\Domain\Reports;

use App\Domain\Orders\OrderTypeClassifier;

class BundleCheckAnalyzer
{
    public function __construct(private readonly OrderTypeClassifier $classifier) {}

    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $financial = $this->text($order['financial_status'] ?? '');
            if (($order['cancelled_at'] ?? null) || in_array($financial, ['pending', 'voided', 'refunded', 'partially_refunded'], true) || (float) ($order['total_price'] ?? 0) == 0 || ($order['shipping_lines'] ?? []) === []) {
                continue;
            }
            $missing = $this->classifier->missingRequired($order);
            if ($missing === []) {
                continue;
            }
            $parts = [];
            foreach ($missing as $type => $items) {
                $parts[] = (count($missing) > 1 ? $type.': ' : '').implode(', ', $items);
            }
            $rows[] = [
                'shopify_id' => $this->text($order['id'] ?? ''), 'order_number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''),
                'financial_status' => $financial, 'fulfillment_status' => $this->text($order['fulfillment_status'] ?? ''),
                'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'order_type' => $this->classifier->classify($order), 'missing_required' => $missing, 'missing_text' => implode('; ', $parts),
            ];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
