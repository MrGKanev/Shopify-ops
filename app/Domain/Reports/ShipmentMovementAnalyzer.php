<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

class ShipmentMovementAnalyzer
{
    /**
     * @param  array<string, mixed>  $tracking
     * @return array{classification: string, actionable: bool, resolves: bool, accepted_at: ?string, carrier_acceptance_at: ?string, last_progress_at: ?string, hours: ?int}
     */
    public function analyze(array $tracking, ?string $labelCreatedAt, int $acceptanceHours, int $staleHours, CarbonImmutable $now): array
    {
        $status = $tracking['status_code'] ?? '';
        $progress = [];
        $acceptances = [];
        $label = $this->timestamp($labelCreatedAt, 'America/Los_Angeles');
        $invalid = $label === null || $label->gt($now);
        foreach ($tracking['events'] ?? [] as $event) {
            if (! is_array($event)) {
                $invalid = true;

                continue;
            }
            $date = $this->timestamp($event['occurred_at'] ?? null);
            if ($date === null || $date->gt($now) || ($label !== null && $date->lt($label))) {
                $invalid = true;

                continue;
            }
            if (($event['status_code'] ?? null) === 'AC') {
                $acceptances[$date->getTimestamp()] = $date;
            }
            if (in_array($event['status_code'] ?? null, ['AC', 'IT', 'AT', 'DE', 'SP'], true)) {
                $progress[$date->getTimestamp()] = $date;
            }
        }
        ksort($progress);
        ksort($acceptances);
        $carrierAcceptance = $acceptances === [] ? null : reset($acceptances);
        $accepted = $progress === [] ? null : reset($progress);
        $latest = $progress === [] ? null : end($progress);
        $hours = $latest === null ? null : (int) floor(($now->getTimestamp() - $latest->getTimestamp()) / 3600);
        $classification = 'movement_unknown';
        if (! $invalid) {
            if ($status === 'DE') {
                $deliveredAt = $this->timestamp($tracking['actual_delivery_date'] ?? null);
                $classification = ($latest !== null || ($deliveredAt !== null && $label !== null && $deliveredAt->gte($label) && $deliveredAt->lte($now))) ? 'delivered' : 'movement_unknown';
            } elseif ($status === 'EX') {
                $classification = 'carrier_exception';
            } elseif ($status === 'AT') {
                $classification = 'delivery_attempt';
            } elseif ($status === 'NY' && $progress === []) {
                $hours = $label === null || $label->gt($now) ? null : (int) floor(($now->getTimestamp() - $label->getTimestamp()) / 3600);
                $classification = $hours === null ? 'movement_unknown' : ($hours >= $acceptanceHours ? 'no_acceptance_record' : 'acceptance_grace');
            } elseif (in_array($status, ['AC', 'IT'], true) && $hours !== null) {
                $classification = $hours >= $staleHours ? 'no_recent_progress' : 'recent_progress';
            } elseif ($status === 'SP') {
                $classification = 'awaiting_collection';
            }
        }

        return ['classification' => $classification, 'actionable' => in_array($classification, ['no_acceptance_record', 'no_recent_progress', 'carrier_exception', 'delivery_attempt'], true), 'resolves' => in_array($classification, ['delivered', 'recent_progress'], true), 'accepted_at' => $accepted?->toIso8601String(), 'carrier_acceptance_at' => ! $invalid && in_array($status, ['AC', 'IT', 'AT', 'DE', 'EX', 'SP'], true) ? $carrierAcceptance?->toIso8601String() : null, 'last_progress_at' => $latest?->toIso8601String(), 'hours' => $hours];
    }

    private function timestamp(mixed $value, ?string $fallbackTimezone = null): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value) || ($fallbackTimezone === null && ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value))) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value, $fallbackTimezone ?? 'UTC');

            return $date->format('Y-m-d') === substr($value, 0, 10) ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
