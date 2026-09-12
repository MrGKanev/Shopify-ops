<?php

namespace App\Domain\Reports;

class NoTrackingAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, string $startDate, string $endDate, int $threshold, int $now): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $missing = [];
            foreach (is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [] as $fulfillment) {
                if (! is_array($fulfillment)) {
                    continue;
                }
                $createdAt = $this->text($fulfillment['created_at'] ?? '');
                $timestamp = strtotime($createdAt);
                $hoursAgo = $timestamp === false ? 0 : (int) floor(($now - $timestamp) / 3600);
                if ($createdAt < "{$startDate}T00:00:00Z" || $createdAt > "{$endDate}T23:59:59Z" || $this->text($fulfillment['tracking_number'] ?? '') !== '' || $hoursAgo < $threshold) {
                    continue;
                }
                $missing[] = ['id' => $this->text($fulfillment['id'] ?? ''), 'created_at' => substr($createdAt, 0, 10), 'hours_ago' => $hoursAgo, 'status' => $this->text($fulfillment['shipment_status'] ?? $fulfillment['status'] ?? ''), 'company' => $this->text($fulfillment['tracking_company'] ?? '')];
            }
            if ($missing === []) {
                continue;
            }
            usort($missing, fn (array $a, array $b): int => $b['hours_ago'] <=> $a['hours_ago']);
            $rows[] = ['shopify_id' => $this->text($order['id'] ?? ''), 'order_number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? ''), 'missing' => $missing];
        }
        usort($rows, fn (array $a, array $b): int => $b['missing'][0]['hours_ago'] <=> $a['missing'][0]['hours_ago']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
