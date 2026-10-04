<?php

namespace App\Domain\Reports;

use App\Domain\Orders\PhoneNumberValidator;
use App\Domain\Reports\Concerns\NormalizesText;

class HighValueNoPhoneAnalyzer
{
    use NormalizesText;

    public function __construct(private readonly PhoneNumberValidator $phones = new PhoneNumberValidator) {}

    /**
     * High-value orders whose shipping phone is missing, or invalid for the shipping country.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function analyze(array $orders, float $minimum, ?string $currency): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
            $phone = $this->text($address['phone'] ?? '');
            $total = is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;
            $orderCurrency = strtoupper($this->text($order['currency'] ?? ''));

            $countryCode = strtoupper($this->text($address['country_code'] ?? ''));
            $phoneIssue = match (true) {
                $phone === '' => 'missing',
                preg_match('/^[A-Z]{2}$/', $countryCode) === 1 && ! $this->phones->isValid($phone, $countryCode) => 'invalid',
                default => null,
            };

            if ($phoneIssue === null || $total < $minimum || ($currency !== null && $orderCurrency !== $currency)) {
                continue;
            }

            $id = $this->text($order['id'] ?? '');
            $rows[] = [
                'id' => ctype_digit($id) ? $id : '',
                'number' => $this->text($order['name'] ?? ''),
                'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10),
                'email' => $this->text($order['email'] ?? ''),
                'total' => $total,
                'currency' => $orderCurrency,
                'phone' => $phone,
                'phone_issue' => $phoneIssue,
                'recipient' => trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? '')),
                'address' => implode(', ', array_filter(array_map(fn (string $key): string => $this->text($address[$key] ?? ''), ['address1', 'address2', 'city', 'province', 'zip', 'country']))),
            ];
        }

        usort($rows, fn (array $left, array $right): int => $right['total'] <=> $left['total']);

        return $rows;
    }
}
