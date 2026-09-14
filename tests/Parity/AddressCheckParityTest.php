<?php

declare(strict_types=1);

use App\Domain\Reports\AddressCheckAnalyzer;
use PHPUnit\Framework\TestCase;

final class AddressCheckParityTest extends TestCase
{
    /**
     * Flags shipping addresses with a problem an operator should fix before
     * the order ships -- 10 distinct rules (missing fields, malformed
     * zip/postal codes, PO Box + carrier conflicts, express shipping
     * without a phone), each with its own severity, so the fixture
     * exercises every rule at least once plus the critical-first sort and
     * the optional PO-Box-only filter.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // no address at all -> critical, single issue
            $this->order('1', null, []),
            // missing name/city/zip/country -> multiple critical issues
            $this->order('2', ['address1' => '123 Main St'], []),
            // short street address -> warning only
            $this->order('3', ['first_name' => 'A', 'last_name' => 'B', 'address1' => '12 St', 'city' => 'X', 'zip' => '90210', 'country_code' => 'US', 'province_code' => 'CA'], []),
            // bad US zip format -> warning
            $this->order('4', ['first_name' => 'A', 'last_name' => 'B', 'address1' => '123 Main Street', 'city' => 'X', 'zip' => 'ABCDE', 'country_code' => 'US', 'province_code' => 'CA'], []),
            // bad CA postal code format -> warning
            $this->order('5', ['first_name' => 'A', 'last_name' => 'B', 'address1' => '123 Main Street', 'city' => 'X', 'zip' => '12345', 'country_code' => 'CA', 'province_code' => 'ON'], []),
            // US, missing province -> warning
            $this->order('6', ['first_name' => 'A', 'last_name' => 'B', 'address1' => '123 Main Street', 'city' => 'X', 'zip' => '90210', 'country_code' => 'US'], []),
            // no phone + express shipping line -> warning
            $this->order('7', ['first_name' => 'A', 'last_name' => 'B', 'address1' => '123 Main Street', 'city' => 'X', 'zip' => '90210', 'country_code' => 'US', 'province_code' => 'CA'], [['title' => 'FedEx Overnight']]),
            // PO Box + FedEx -> po_box_carrier warning
            $this->order('8', ['first_name' => 'A', 'last_name' => 'B', 'address1' => 'PO Box 123', 'city' => 'X', 'zip' => '90210', 'country_code' => 'US', 'province_code' => 'CA', 'phone' => '555-1234'], [['title' => 'FedEx Ground']]),
            // PO Box + generic carrier -> po_box warning
            $this->order('9', ['first_name' => 'A', 'last_name' => 'B', 'address1' => 'PO Box 456', 'city' => 'X', 'zip' => '90210', 'country_code' => 'US', 'province_code' => 'CA', 'phone' => '555-1234'], [['title' => 'Standard Shipping']]),
            // fully clean address -> no issues, no row
            $this->order('10', ['first_name' => 'A', 'last_name' => 'B', 'address1' => '123 Main Street', 'city' => 'X', 'zip' => '90210', 'country_code' => 'US', 'province_code' => 'CA', 'phone' => '555-1234'], []),
        ];

        $legacyMethod = new ReflectionMethod(\OrderAnomalyPageLoader::class, 'buildAddrCheckRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, false);
        $legacyPoBoxRows = $legacyMethod->invoke(null, $orders, true);

        $analyzer = new AddressCheckAnalyzer();
        $laravelRows = $analyzer->analyze($orders, false);
        $laravelPoBoxRows = $analyzer->analyze($orders, true);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
        $this->assertSame($this->summarize($legacyPoBoxRows), $this->summarize($laravelPoBoxRows));
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, ?array $shippingAddress, array $shippingLines): array
    {
        return [
            'id' => $orderNumber,
            'name' => "#{$orderNumber}",
            'email' => "c{$orderNumber}@example.com",
            'created_at' => '2026-01-01T00:00:00Z',
            'shipping_address' => $shippingAddress,
            'shipping_lines' => $shippingLines,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, severity: mixed, codes: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'] ?? $r['number'],
            'severity' => $r['severity'],
            'codes' => array_column($r['issues'], 'code'),
        ], $rows);
    }
}
