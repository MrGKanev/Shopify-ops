<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Concerns\NormalizesText;

class CountryMismatchAnalyzer
{
    use NormalizesText;

    /** @param list<array<string, mixed>> $orders @return array{rows: list<array<string, mixed>>, skipped_missing_country: int} */
    public function analyze(array $orders): array
    {
        $rows = [];
        $skippedMissingCountry = 0;
        foreach ($orders as $order) {
            $billing = is_array($order['billing_address'] ?? null) ? $order['billing_address'] : [];
            $shipping = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
            $billingCountry = $this->country($billing);
            $shippingCountry = $this->country($shipping);

            if ($billingCountry === '' || $shippingCountry === '') {
                $skippedMissingCountry++;

                continue;
            }

            if ($billingCountry === $shippingCountry) {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = [
                'id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''),
                'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => $this->text($order['currency'] ?? ''),
                'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? ''),
                'billing_country' => $billingCountry, 'shipping_country' => $shippingCountry,
                'billing_name' => trim($this->text($billing['first_name'] ?? '').' '.$this->text($billing['last_name'] ?? '')),
            ];
        }
        usort($rows, fn (array $left, array $right): int => strcmp($right['created_at'], $left['created_at']));

        return ['rows' => $rows, 'skipped_missing_country' => $skippedMissingCountry];
    }

    /** @param array<string, mixed> $address */
    private function country(array $address): string
    {
        return strtoupper($this->text($address['country_code'] ?? $address['country'] ?? ''));
    }
}
