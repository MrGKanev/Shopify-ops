<?php

namespace App\Domain\Reports;

use App\Domain\Orders\OrderTypeClassifier;

class FulfillmentSlaAnalyzer
{
    public function __construct(private readonly OrderTypeClassifier $classifier) {}

    /** @param list<array<string, mixed>> $orders @return list<array<string, mixed>> */
    public function analyze(array $orders, int $threshold, int $now): array
    {
        $rows = [];
        foreach ($orders as $order) {
            if (($order['cancelled_at'] ?? null) || in_array($order['financial_status'] ?? '', ['refunded', 'voided'], true)) {
                continue;
            }
            $created = strtotime(is_scalar($order['created_at'] ?? null) ? (string) $order['created_at'] : '');
            if ($created === false) {
                continue;
            }
            $fulfilledAt = '';
            foreach (is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [] as $fulfillment) {
                $date = is_array($fulfillment) && is_scalar($fulfillment['created_at'] ?? null) ? (string) $fulfillment['created_at'] : '';
                if ($date !== '' && ($fulfilledAt === '' || $date < $fulfilledAt)) {
                    $fulfilledAt = $date;
                }
            }
            $fulfilled = $fulfilledAt === '' ? false : strtotime($fulfilledAt);
            $days = (int) floor((($fulfilled === false ? $now : $fulfilled) - $created) / 86400);
            if ($days < $threshold) {
                continue;
            }
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
            $shippingLine = is_array($order['shipping_lines'][0] ?? null) ? $order['shipping_lines'][0] : [];
            $province = $this->text($address['province_code'] ?? '');
            $country = $this->text($address['country_code'] ?? '');
            $rows[] = [
                'shopify_id' => $this->text($order['id'] ?? ''), 'order_number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'fulfilled_at' => substr($fulfilledAt, 0, 10), 'days' => $days,
                'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0,
                'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? '') ?: 'unfulfilled',
                'method' => $this->text($shippingLine['title'] ?? '') ?: 'Unknown', 'region' => implode(', ', array_filter([$province, $country])) ?: 'Unknown',
                'order_type' => $this->classifier->classify($order),
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['days'] <=> $a['days']);

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
