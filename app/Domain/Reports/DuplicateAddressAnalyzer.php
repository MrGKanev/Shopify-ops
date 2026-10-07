<?php

namespace App\Domain\Reports;

use App\Domain\Orders\PhoneNumberValidator;
use App\Domain\Reports\Concerns\NormalizesText;

class DuplicateAddressAnalyzer
{
    use NormalizesText;

    public function __construct(
        private readonly PhoneNumberValidator $phones = new PhoneNumberValidator,
        private readonly AddressCheckAnalyzer $addressChecks = new AddressCheckAnalyzer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{address_line: string, address_name: string, order_count: int, emails: list<string>, orders: list<array<string, mixed>>, names: list<string>, warning_only: bool, email_count: int, review_context: array{shared_valid_phone: bool, company_present: bool, address_quality_codes: list<string>}}>
     */
    public function analyze(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : null;
            $email = mb_strtolower($this->text($order['email'] ?? ''));
            if ($address === null || $email === '') {
                continue;
            }
            $key = $this->addressKey($order);
            if ($key === null) {
                continue;
            }
            $groups[$key] ??= ['address' => $address, 'emails' => [], 'names' => [], 'source_orders' => [], 'orders' => []];
            $groups[$key]['emails'][$email] = true;
            $name = $this->recipientName($order);
            if ($name !== '') {
                $groups[$key]['names'][mb_strtolower($name)] = $name;
            }
            $groups[$key]['source_orders'][] = $order;
            $id = $this->text($order['id'] ?? '');
            $groups[$key]['orders'][] = ['shopify_id' => ctype_digit($id) ? $id : '', 'order_number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'recipient_name' => $name, 'company' => $this->text($address['company'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'currency' => strtoupper($this->text($order['currency'] ?? '')), 'fulfillment' => $this->text($order['fulfillment_status'] ?? '')];
        }
        $rows = [];
        foreach ($groups as $group) {
            if (count($group['emails']) < 2) {
                continue;
            }
            $address = $group['address'];
            $rows[] = ['address_line' => implode(', ', array_filter([$this->text($address['address1'] ?? ''), $this->text($address['address2'] ?? ''), $this->text($address['city'] ?? ''), $this->text($address['province_code'] ?? ''), $this->text($address['zip'] ?? ''), $this->text($address['country_code'] ?? '')])), 'address_name' => trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? '')), 'review_context' => $this->reviewContext($group['source_orders']), 'names' => array_values($group['names']), 'warning_only' => true, 'email_count' => count($group['emails']), 'order_count' => count($group['orders']), 'emails' => array_keys($group['emails']), 'orders' => $group['orders']];
        }
        usort($rows, fn (array $a, array $b): int => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{shared_valid_phone: bool, company_present: bool, address_quality_codes: list<string>}
     */
    public function reviewContext(array $orders): array
    {
        $phones = [];
        $quality = [];
        $companyPresent = false;
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
            $country = strtoupper($this->text($address['country_code'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $phone = $this->phones->toE164($this->text($address['phone'] ?? ''), $country);
                if ($phone !== null) {
                    $phones[$phone] = ($phones[$phone] ?? 0) + 1;
                }
            }
            $companyPresent = $companyPresent || $this->text($address['company'] ?? '') !== '';
            foreach ($this->addressChecks->check($address, $order) as $issue) {
                $quality[$issue['code']] = true;
            }
        }

        return ['shared_valid_phone' => $phones !== [] && max($phones) > 1, 'company_present' => $companyPresent, 'address_quality_codes' => array_keys($quality)];
    }

    /** @param array<string, mixed> $order */
    public function addressKey(array $order): ?string
    {
        $address = $order['shipping_address'] ?? null;
        if (! is_array($address)) {
            return null;
        }
        $parts = [];
        foreach (['address1', 'address2', 'city', 'province_code', 'zip', 'country_code'] as $field) {
            $value = $field === 'country_code' ? ($address[$field] ?? $address['country'] ?? '') : ($address[$field] ?? '');
            $parts[] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['.', ','], '', $this->text($value))) ?? ''));
        }
        if ($parts[0] === '' || $parts[2] === '' || $parts[5] === '') {
            return null;
        }

        return implode('|', $parts);
    }

    /** @param array<string, mixed> $order */
    public function recipientName(array $order): string
    {
        $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];

        return $this->text($address['name'] ?? '') ?: trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? ''));
    }
}
