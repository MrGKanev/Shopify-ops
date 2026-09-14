<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\CountryMismatchAnalyzer;
use PHPUnit\Framework\TestCase;

class CountryMismatchAnalyzerTest extends TestCase
{
    public function test_flags_different_iso_codes_and_sorts_deterministically(): void
    {
        // #2 and #3 share the same created_at -- legacy's sort has no
        // tiebreaker beyond created_at (PHP 8's stable usort() just
        // preserves input order for ties), so #2 stays before #3.
        $result = (new CountryMismatchAnalyzer)->analyze([$this->order('#1', 'us', 'US', '2026-09-01'), $this->order('#2', 'US', 'CA', '2026-09-02'), $this->order('#3', 'GB', 'FR', '2026-09-02')]);
        $this->assertSame(['#2', '#3'], array_column($result['rows'], 'number'));
        $this->assertSame('US', $result['rows'][0]['billing_country']);
        $this->assertSame(0, $result['skipped_missing_country']);
    }

    public function test_missing_country_falls_back_to_full_name_legacy_does_not_validate_code_format(): void
    {
        // #1: billing country_code is an empty string (present, not absent)
        // -> missing, matches legacy's `?? ` (only falls through on null/absent).
        // #2: no country_code at all, only the full `country` name field --
        // legacy falls back to it and does NOT validate the result looks like
        // an ISO code, so this compares as a real mismatch, not a skip.
        // #3: shipping country_code is empty -> missing.
        $result = (new CountryMismatchAnalyzer)->analyze([
            $this->order('#1', '', 'CA'),
            $this->orderWithCountryName('#2', 'United States', 'CA'),
            $this->order('#3', 'US', ''),
        ]);
        $this->assertSame(['#2'], array_column($result['rows'], 'number'));
        $this->assertSame('UNITED STATES', $result['rows'][0]['billing_country']);
        $this->assertSame(2, $result['skipped_missing_country']);
    }

    private function order(string $number, string $billing, string $shipping, string $date = '2026-09-01'): array
    {
        return ['id' => 1, 'name' => $number, 'created_at' => $date, 'email' => 'a@example.com', 'total_price' => '10.25', 'currency' => 'USD', 'financial_status' => 'paid', 'billing_address' => ['country_code' => $billing, 'first_name' => 'Ada'], 'shipping_address' => ['country_code' => $shipping]];
    }

    private function orderWithCountryName(string $number, string $billingCountryName, string $shipping, string $date = '2026-09-01'): array
    {
        return ['id' => 1, 'name' => $number, 'created_at' => $date, 'email' => 'a@example.com', 'total_price' => '10.25', 'currency' => 'USD', 'financial_status' => 'paid', 'billing_address' => ['country' => $billingCountryName, 'first_name' => 'Ada'], 'shipping_address' => ['country_code' => $shipping]];
    }
}
