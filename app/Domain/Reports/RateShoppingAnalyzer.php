<?php

namespace App\Domain\Reports;

class RateShoppingAnalyzer
{
    /** @param list<array<string, mixed>> $quotes
     * @param list<string> $allowedServices
     * @return list<array<string, mixed>> */
    public function normalize(array $quotes, array $allowedServices): array
    {
        $rows = [];
        foreach ($quotes as $quote) {
            $rows[] = ['service_code' => $quote['serviceCode'], 'service_name' => $quote['serviceName'] ?? $quote['serviceCode'], 'shipment_cost' => (float) $quote['shipmentCost'], 'other_cost' => (float) $quote['otherCost'], 'total' => round((float) $quote['shipmentCost'] + (float) $quote['otherCost'], 2), 'eligible' => in_array($quote['serviceCode'], $allowedServices, true)];
        }
        usort($rows, fn (array $a, array $b): int => $a['total'] <=> $b['total'] ?: strcmp($a['service_code'], $b['service_code']));

        return $rows;
    }

    /** @param list<array<string, mixed>> $quotes
     * @return array{cheapest: array<string, mixed>|null, reference: array<string, mixed>|null, difference: float|null} */
    public function compare(array $quotes, ?string $referenceService): array
    {
        $eligible = array_values(array_filter($quotes, fn (array $quote): bool => $quote['eligible']));
        usort($eligible, fn (array $a, array $b): int => $a['total'] <=> $b['total']);
        $reference = array_find($eligible, fn (array $quote): bool => $quote['service_code'] === $referenceService);
        $cheapest = $eligible[0] ?? null;

        return ['cheapest' => $cheapest, 'reference' => $reference, 'difference' => $reference === null || $cheapest === null ? null : round($reference['total'] - $cheapest['total'], 2)];
    }
}
