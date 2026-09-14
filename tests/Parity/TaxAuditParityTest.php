<?php

declare(strict_types=1);

use App\Domain\Reports\TaxAuditAnalyzer;
use PHPUnit\Framework\TestCase;

final class TaxAuditParityTest extends TestCase
{
    /** Flags paid orders above a minimum with $0 tax charged to a non-exempt customer -- a compliance review signal. */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // above minimum, zero tax, not exempt -> flagged
            $this->order(1, '20.00', '0.00', false),
            // above minimum, zero tax, but customer IS tax-exempt -> excluded
            $this->order(2, '20.00', '0.00', true),
            // above minimum, tax was actually charged -> excluded
            $this->order(3, '20.00', '1.50', false),
            // below minimum -> excluded regardless of tax
            $this->order(4, '2.00', '0.00', false),
            // exactly at the minimum -> included (>= not >)
            $this->order(5, '5.00', '0.00', false),
        ];

        $legacyMethod = new ReflectionMethod(\SimpleScanPageLoader::class, 'buildTaxAuditRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, 5.0);

        $laravelRows = (new TaxAuditAnalyzer())->analyze($orders, 5.0);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $totalPrice, string $totalTax, bool $taxExempt): array
    {
        return ['id' => $id, 'name' => "#{$id}", 'email' => "c{$id}@example.com", 'created_at' => '2026-01-01T00:00:00Z', 'total_price' => $totalPrice, 'total_tax' => $totalTax, 'customer_tax_exempt' => $taxExempt, 'financial_status' => 'paid'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): string => (string) ($r['shopify_id'] ?? $r['id']), $rows);
    }
}
