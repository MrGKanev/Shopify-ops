<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\HighValueNoPhoneAnalyzer;
use PHPUnit\Framework\TestCase;

class HighValueNoPhoneAnalyzerTest extends TestCase
{
    public function test_includes_blank_phone_at_threshold_and_sorts_deterministically(): void
    {
        // #B and #C tie on total -- legacy's sort has no tiebreaker beyond
        // total (PHP 8's stable usort() just preserves input order for
        // ties), so #B (inserted first) stays before #C.
        $rows = (new HighValueNoPhoneAnalyzer)->analyze([
            $this->order('#A', '200.00', ' ', '2026-09-01'),
            $this->order('#B', '900.00', '', '2026-09-02'),
            $this->order('#C', '900.00', '', '2026-09-03'),
        ], 200.0, 'USD');

        $this->assertSame(['#B', '#C', '#A'], array_column($rows, 'number'));
        $this->assertSame(200.0, $rows[2]['total']);
    }

    public function test_excludes_phone_below_threshold_and_other_currency(): void
    {
        $rows = (new HighValueNoPhoneAnalyzer)->analyze([
            $this->order('#phone', '500', '555'),
            $this->order('#below', '199.99', ''),
            $this->order('#eur', '500', '', currency: 'EUR'),
            $this->order('#invalid', 'invalid', ''),
        ], 200.0, 'USD');

        $this->assertSame([], $rows);
    }

    public function test_phones_invalid_for_the_shipping_country_are_flagged(): void
    {
        $invalid = $this->order('#invalid', '500', '555');
        $invalid['shipping_address']['country_code'] = 'US';
        $valid = $this->order('#valid', '500', '617-555-0100');
        $valid['shipping_address']['country_code'] = 'US';
        $international = $this->order('#international', '500', '+359 888 123 456');
        $international['shipping_address']['country_code'] = 'US';
        $missing = $this->order('#missing', '400', '');

        $rows = (new HighValueNoPhoneAnalyzer)->analyze([$invalid, $valid, $international, $missing], 200.0, 'USD');

        $this->assertSame(['#invalid', '#missing'], array_column($rows, 'number'));
        $this->assertSame(['invalid', 'missing'], array_column($rows, 'phone_issue'));
        $this->assertSame('555', $rows[0]['phone']);
    }

    public function test_cancelled_orders_are_still_flagged(): void
    {
        // Legacy's buildHvOrderRows() has no cancelled-order exclusion at
        // all -- a high-value order with no phone is still a delivery/fraud
        // risk worth reviewing regardless of cancellation status.
        $rows = (new HighValueNoPhoneAnalyzer)->analyze([
            $this->order('#cancelled', '500', '', cancelledAt: '2026-09-01'),
        ], 200.0, 'USD');

        $this->assertSame(['#cancelled'], array_column($rows, 'number'));
    }

    public function test_missing_address_is_included_and_identified_by_blank_address(): void
    {
        $order = $this->order('#A', '500', '');
        unset($order['shipping_address']);

        $rows = (new HighValueNoPhoneAnalyzer)->analyze([$order], 200.0, 'USD');

        $this->assertSame('', $rows[0]['recipient']);
        $this->assertSame('', $rows[0]['address']);
    }

    private function order(string $name, string $total, string $phone, string $date = '2026-09-01', string $currency = 'USD', string $cancelledAt = ''): array
    {
        return ['id' => 1, 'name' => $name, 'created_at' => $date, 'email' => 'buyer@example.com', 'total_price' => $total, 'currency' => $currency, 'cancelled_at' => $cancelledAt, 'shipping_address' => ['phone' => $phone, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'address1' => '1 Main']];
    }
}
