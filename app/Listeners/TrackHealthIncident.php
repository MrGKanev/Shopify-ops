<?php

namespace App\Listeners;

use App\Models\HealthIncident;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Events\CheckEndedEvent;

class TrackHealthIncident
{
    public function handle(CheckEndedEvent $event): void
    {
        $status = (string) $event->result->status;
        if ($status === 'skipped') {
            return;
        }

        DB::transaction(function () use ($event, $status): void {
            $openIncident = HealthIncident::query()
                ->where('check_name', $event->check->getName())
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->first();

            if ($status === 'ok') {
                $openIncident?->update([
                    'last_observed_at' => $event->result->ended_at,
                    'resolved_at' => $event->result->ended_at,
                ]);

                return;
            }

            $summary = $event->result->getNotificationMessage() ?: $event->result->getShortSummary();
            if ($openIncident === null) {
                HealthIncident::create([
                    'check_name' => $event->check->getName(),
                    'check_label' => $event->check->getLabel(),
                    'severity' => $status,
                    'summary' => $summary,
                    'started_at' => $event->result->ended_at,
                    'last_observed_at' => $event->result->ended_at,
                ]);

                return;
            }

            $openIncident->update([
                'severity' => $status,
                'summary' => $summary,
                'observations' => $openIncident->observations + 1,
                'last_observed_at' => $event->result->ended_at,
            ]);
        });
    }
}
