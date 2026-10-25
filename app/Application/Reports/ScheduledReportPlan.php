<?php

namespace App\Application\Reports;

use App\Integration;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use LogicException;

class ScheduledReportPlan
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function supports(ReportRun $run): bool
    {
        return $this->registry->find($run->tool)?->scheduleAction === $run->report
            && $run->status === 'completed' && $run->result()?->truncated === false
            && empty($run->parameters['after'])
            && ($run->tool !== 'product_sync' || ($run->parameters['mode'] ?? null) === 'catalog');
    }

    /** @return class-string */
    public function action(ReportSchedule $schedule, Store $store, bool $requireCredentials = true): string
    {
        $tool = $this->registry->find($schedule->tool);
        if ($tool?->scheduleAction === null) {
            throw new LogicException('This report cannot be scheduled.');
        }
        if ($requireCredentials && (($tool->requires(Integration::Shopify) && $store->missingShopifyCredentials())
            || ($tool->requires(Integration::ShipStation) && $store->missingShipStationCredentials()))) {
            throw new LogicException('The report integration is not configured.');
        }

        return $tool->scheduleAction;
    }

    public function nextRun(ReportSchedule $schedule, CarbonImmutable $after): CarbonImmutable
    {
        $local = $after->setTimezone($schedule->timezone);
        $candidate = $local->setTimeFromTimeString($schedule->time);
        if ($schedule->frequency === 'weekly') {
            $candidate = $candidate->addDays(($schedule->weekday - $candidate->dayOfWeek + 7) % 7);
        } elseif ($schedule->frequency === 'monthly') {
            $candidate = $candidate->setDay($schedule->month_day);
        }
        if ($candidate->lte($local)) {
            $candidate = match ($schedule->frequency) {
                'weekly' => $candidate->addWeek(),
                'monthly' => $candidate->addMonthNoOverflow(),
                default => $candidate->addDay(),
            };
        }

        return $candidate->utc();
    }

    /** @return array{arguments: list<mixed>, parameters: array<string, mixed>, start: ?string, end: ?string} */
    public function inputs(ReportSchedule $schedule, CarbonImmutable $now): array
    {
        $arguments = $schedule->arguments;
        $parameters = $schedule->parameters;
        $start = $end = null;
        if ($schedule->uses_date_range) {
            $endDate = $now->setTimezone($schedule->timezone)->subDays($schedule->end_offset_days);
            $end = $endDate->toDateString();
            $start = $endDate->subDays($schedule->lookback_days - 1)->toDateString();
            $arguments[0] = $parameters['start_date'] = $start;
            $arguments[1] = $parameters['end_date'] = $end;
        }
        if ($schedule->tool === 'gift_cards') {
            $arguments[1] = $now->getTimestamp();
        } elseif ($schedule->tool === 'disputes') {
            $arguments[0] = $now->getTimestamp();
        } elseif ($schedule->tool === 'tag_audit') {
            $arguments[2] = $now->setTimezone($schedule->timezone)->subDays(90)->toDateString();
        } elseif ($schedule->tool === 'tag_policy') {
            $arguments[2] = config('tag-policy', []);
        }

        return ['arguments' => array_values($arguments), 'parameters' => $parameters, 'start' => $start, 'end' => $end];
    }

    public function assertTemplate(ReportRun $run): void
    {
        if (! $this->supports($run)) {
            throw ValidationException::withMessages(['source_run_id' => __('Choose a complete supported report run without a pagination cursor.')]);
        }
    }
}
