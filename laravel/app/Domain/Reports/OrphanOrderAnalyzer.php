<?php

namespace App\Domain\Reports;

class OrphanOrderAnalyzer
{
    /** @param list<array<string, mixed>> $shipStationOrders @param list<array<string, mixed>> $shopifyOrders @return list<array<string, mixed>> */
    public function analyze(array $shipStationOrders, array $shopifyOrders): array
    {
        $shopifyNumbers = [];
        foreach ($shopifyOrders as $order) {
            $number = $this->normalize($order['name'] ?? $order['order_number'] ?? '');
            if ($number !== '') {
                $shopifyNumbers[$number] = true;
            }
        }
        $rows = [];
        foreach ($shipStationOrders as $order) {
            $raw = $this->text($order['orderNumber'] ?? '');
            $keys = $this->keys($raw);
            if ($keys === [] || array_any($keys, fn (string $key): bool => isset($shopifyNumbers[$key]))) {
                continue;
            }
            $shipTo = is_array($order['shipTo'] ?? null) ? $order['shipTo'] : [];
            $rows[] = ['ss_order_id' => $this->text($order['orderId'] ?? ''), 'order_number' => $raw, 'order_status' => $this->text($order['orderStatus'] ?? ''), 'order_date' => substr($this->text($order['orderDate'] ?? ''), 0, 10), 'customer' => $this->text($shipTo['name'] ?? ''), 'email' => $this->text($order['customerEmail'] ?? ''), 'total' => is_numeric($order['orderTotal'] ?? null) ? (float) $order['orderTotal'] : 0.0];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['order_date'], $a['order_date']));

        return $rows;
    }

    /** @return list<string> */
    private function keys(string $raw): array
    {
        $keys = [];
        $full = $this->normalize($raw);
        if ($full !== '') {
            $keys[] = $full;
        }
        preg_match_all('/\d+/', $raw, $matches);
        foreach ($matches[0] as $segment) {
            if ($segment !== $full && strlen($segment) >= 4) {
                $keys[] = $segment;
            }
        }

        return array_values(array_unique($keys));
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
