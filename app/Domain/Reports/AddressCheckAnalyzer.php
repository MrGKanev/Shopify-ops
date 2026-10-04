<?php

namespace App\Domain\Reports;

use App\Domain\Orders\AddressFormatRules;
use App\Domain\Orders\PhoneNumberValidator;
use App\Domain\Reports\Concerns\NormalizesText;

class AddressCheckAnalyzer
{
    use NormalizesText;

    public function __construct(
        private readonly AddressFormatRules $formats = new AddressFormatRules,
        private readonly PhoneNumberValidator $phones = new PhoneNumberValidator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function analyze(array $orders, bool $poBoxOnly = false): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : null;
            $issues = $this->check($address, $order);
            if ($issues === [] || ($poBoxOnly && array_intersect(['po_box', 'po_box_carrier'], array_column($issues, 'code')) === [])) {
                continue;
            }
            $id = $this->text($order['id'] ?? '');
            $rows[] = ['id' => ctype_digit($id) ? $id : '', 'number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'email' => $this->text($order['email'] ?? ''), 'address' => $address, 'issues' => $issues, 'severity' => in_array('critical', array_column($issues, 'level'), true) ? 'critical' : 'warning'];
        }
        usort($rows, fn (array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @param  array<string, mixed>  $order
     * @return list<array{level: 'critical'|'warning', code: string, message: string}>
     */
    public function check(?array $address, array $order = []): array
    {
        if ($address === null || $address === []) {
            return [['level' => 'critical', 'code' => 'no_address', 'message' => 'No shipping address on this order']];
        }
        $name = trim($this->text($address['first_name'] ?? '').' '.$this->text($address['last_name'] ?? ''));
        $street = $this->text($address['address1'] ?? '');
        $city = $this->text($address['city'] ?? '');
        $zip = $this->text($address['zip'] ?? '');
        $country = strtoupper($this->text($address['country_code'] ?? $address['country'] ?? ''));
        $countryCode = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '';
        $province = $this->text($address['province_code'] ?? '');
        $phone = $this->text($address['phone'] ?? '');
        $issues = [];
        if ($name === '') {
            $issues[] = ['level' => 'critical', 'code' => 'no_name', 'message' => 'Missing recipient name'];
        }
        if ($street === '') {
            $issues[] = ['level' => 'critical', 'code' => 'no_address1', 'message' => 'Missing street address'];
        } elseif (mb_strlen($street) < 5) {
            $issues[] = ['level' => 'warning', 'code' => 'short_address', 'message' => 'Street address is suspiciously short'];
        }
        if ($city === '') {
            $issues[] = ['level' => 'critical', 'code' => 'no_city', 'message' => 'Missing city'];
        }
        if ($zip === '') {
            if ($countryCode === '' || $this->formats->requiresPostalCode($countryCode)) {
                $issues[] = ['level' => 'critical', 'code' => 'no_zip', 'message' => 'Missing postal / ZIP code'];
            }
        } elseif ($countryCode !== '' && ! $this->formats->isValidPostalCode($countryCode, $zip)) {
            $issues[] = ['level' => 'warning', 'code' => 'bad_zip', 'message' => 'Postal / ZIP code format is invalid for the shipping country'];
        }
        if ($country === '') {
            $issues[] = ['level' => 'critical', 'code' => 'no_country', 'message' => 'Missing country'];
        } elseif ($province === '' && $countryCode !== '' && $this->formats->requiresAdministrativeArea($countryCode)) {
            $issues[] = ['level' => 'warning', 'code' => 'no_province', 'message' => 'Missing state / province (required for this country)'];
        }
        if ($phone !== '' && $countryCode !== '' && ! $this->phones->isValid($phone, $countryCode)) {
            $issues[] = ['level' => 'warning', 'code' => 'invalid_phone', 'message' => 'Phone number is not valid for the shipping country'];
        }
        $shippingTitles = implode(' ', array_map(fn (mixed $line): string => is_array($line) ? $this->text($line['title'] ?? '') : '', is_array($order['shipping_lines'] ?? null) ? $order['shipping_lines'] : []));
        if ($phone === '' && preg_match('/overnight|express|priority|fedex|ups/i', $shippingTitles) === 1) {
            $issues[] = ['level' => 'warning', 'code' => 'no_phone_express', 'message' => 'No phone number - carrier may require it for express shipping'];
        }
        if ($street !== '' && preg_match('/\bbox\b/i', $street) === 1) {
            $issues[] = preg_match('/fedex|ups|dhl/i', $shippingTitles) === 1
                ? ['level' => 'warning', 'code' => 'po_box_carrier', 'message' => 'PO Box - carrier cannot deliver (FedEx/UPS/DHL do not deliver to PO Boxes)']
                : ['level' => 'warning', 'code' => 'po_box', 'message' => 'PO Box address - confirm your shipping carrier accepts PO Box deliveries'];
        }

        return $issues;
    }
}
