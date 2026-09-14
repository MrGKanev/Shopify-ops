<?php

declare(strict_types=1);

use App\Domain\Reports\CarrierPerformanceAnalyzer;
use PHPUnit\Framework\TestCase;

final class CarrierPerfParityTest extends TestCase
{
    /**
     * Ops scans this report top-to-bottom to see which carrier ships the
     * most volume, so a silently different tie order for equal-count
     * carriers isn't cosmetic -- it changes which carrier reads as "first"
     * when two are tied.
     */
    public function test_rows_match_legacy(): void
    {
        $shipments = [
            // ZebraShip: 2 shipments, both delivered on time -> count 2
            $this->shipment('ZebraShip', '2026-01-01', '2026-01-02'),
            $this->shipment('ZebraShip', '2026-01-01', '2026-01-03'),
            // AlphaShip: 2 shipments -> also count 2, a tie with ZebraShip,
            // but appears SECOND in the raw shipment list -- exercises
            // whether the tie is broken alphabetically or by insertion order
            $this->shipment('AlphaShip', '2026-01-01', '2026-01-02'),
            $this->shipment('AlphaShip', '2026-01-01', '2026-01-08'), // late (>5 days)
            // BetaShip: 1 shipment, no delivery date -> counts but excluded from avg/late
            $this->shipment('BetaShip', '2026-01-01', null),
            // blank carrier code -> groups under 'Unknown'
            $this->shipment('', '2026-01-01', '2026-01-02'),
            // bad data: delivered before shipped -> counts but excluded from avg/late
            $this->shipment('BetaShip', '2026-01-05', '2026-01-01'),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildCarrierPerfRows');
        $legacyRows = $legacyMethod->invoke(null, $shipments);

        $laravelRows = (new CarrierPerformanceAnalyzer())->analyze($shipments);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function shipment(string $carrierCode, string $shipDate, ?string $deliveryDate): array
    {
        return ['carrierCode' => $carrierCode, 'shipDate' => $shipDate, 'deliveryDate' => $deliveryDate];
    }

    /**
     * Field order in each row's array is irrelevant (both sides are
     * associative, accessed by key everywhere); only the row order and
     * field values matter, so this normalizes key order before comparing.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{carrier: mixed, count: mixed, with_delivery: mixed, avg_days: mixed, late_count: mixed, late_pct: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'carrier' => $r['carrier'],
            'count' => $r['count'],
            'with_delivery' => $r['with_delivery'],
            'avg_days' => $r['avg_days'],
            'late_count' => $r['late_count'],
            'late_pct' => $r['late_pct'],
        ], $rows);
    }
}
