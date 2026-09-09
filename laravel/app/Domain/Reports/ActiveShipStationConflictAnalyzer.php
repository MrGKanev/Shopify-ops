<?php

namespace App\Domain\Reports;

class ActiveShipStationConflictAnalyzer
{
    /** @param list<array<string, mixed>> $shopifyOrders @param list<array<string, mixed>> $shipStationOrders @return array{scanned: int, rows: list<array<string, mixed>>} */
    public function analyze(array $shopifyOrders, array $shipStationOrders): array
    {
        $exceptions = [];
        foreach ($shopifyOrders as $order) {
            if (! ($order['cancelled_at'] ?? null) && ! in_array($order['financial_status'] ?? '', ['refunded', 'partially_refunded'], true)) {
                continue;
            }
            $key = $this->text($order['id'] ?? '') ?: spl_object_id((object) $order);
            $exceptions[$key] = $order;
        }
        $active = [];
        foreach ($shipStationOrders as $order) {
            foreach ($this->keys($this->text($order['orderNumber'] ?? '')) as $key) {
                $active[$key][] = $order;
            }
        }
        $rows = [];
        foreach ($exceptions as $order) {
            $matches = $active[$this->normalize($order['name'] ?? $order['order_number'] ?? '')] ?? [];
            foreach ($matches as $shipStation) {
                $rows[] = ['shopify_id' => $this->text($order['id'] ?? ''), 'order_number' => $this->text($order['name'] ?? $order['order_number'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'issue' => ($order['cancelled_at'] ?? null) ? 'cancelled' : $this->text($order['financial_status'] ?? 'refunded'), 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'financial' => $this->text($order['financial_status'] ?? ''), 'cancelled_at' => substr($this->text($order['cancelled_at'] ?? ''), 0, 10), 'ss_order_id' => $this->text($shipStation['orderId'] ?? ''), 'ss_status' => $this->text($shipStation['orderStatus'] ?? ''), 'ss_date' => substr($this->text($shipStation['orderDate'] ?? $shipStation['createDate'] ?? ''), 0, 10), 'ss_total' => is_numeric($shipStation['orderTotal'] ?? null) ? (float) $shipStation['orderTotal'] : 0.0];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return ['scanned' => count($exceptions), 'rows' => $rows];
    }

    /** @return list<string> */
    private function keys(string $raw): array
    {
        $keys = [];
        $full = $this->normalize($raw);
        if ($full !== '') {
            $keys[] = $full;
        }preg_match_all('/\d+/', $raw, $matches);
        foreach ($matches[0] as $segment) {
            if ($segment !== $full && strlen($segment) >= 4) {
                $keys[] = $segment;
            }
        }

return array_unique($keys);
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/\D+/', '', $this->text($value)) ?? '';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
