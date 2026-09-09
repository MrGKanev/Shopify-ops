<?php

namespace App\Domain\Reports;

class CarrierPerformanceAnalyzer
{
    /** @param list<array<string, mixed>> $shipments @return list<array{carrier: string, count: int, with_delivery: int, avg_days: float|null, late_count: int, late_pct: float|null}> */
    public function analyze(array $shipments): array
    {
        $carriers = [];
        foreach ($shipments as $shipment) {
            $carrier = trim(is_scalar($shipment['carrierCode'] ?? null) ? (string) $shipment['carrierCode'] : '') ?: 'Unknown';
            $carriers[$carrier] ??= ['carrier' => $carrier, 'count' => 0, 'with_delivery' => 0, 'total_days' => 0, 'late_count' => 0];
            $carriers[$carrier]['count']++;
            $shipped = strtotime(is_scalar($shipment['shipDate'] ?? null) ? (string) $shipment['shipDate'] : '');
            $delivered = strtotime(is_scalar($shipment['deliveryDate'] ?? null) ? (string) $shipment['deliveryDate'] : '');
            if ($shipped === false || $delivered === false || $delivered < $shipped) {
                continue;
            }
            $days = (int) ceil(($delivered - $shipped) / 86400);
            $carriers[$carrier]['with_delivery']++;
            $carriers[$carrier]['total_days'] += $days;
            $carriers[$carrier]['late_count'] += (int) ($days > 5);
        }

        $rows = array_map(function (array $carrier): array {
            $delivered = $carrier['with_delivery'];

            return [
                'carrier' => $carrier['carrier'],
                'count' => $carrier['count'],
                'with_delivery' => $delivered,
                'avg_days' => $delivered ? round($carrier['total_days'] / $delivered, 1) : null,
                'late_count' => $carrier['late_count'],
                'late_pct' => $delivered ? round($carrier['late_count'] / $delivered * 100, 1) : null,
            ];
        }, array_values($carriers));
        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $a['carrier'] <=> $b['carrier']);

        return $rows;
    }
}
