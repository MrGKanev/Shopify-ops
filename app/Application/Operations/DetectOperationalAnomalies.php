<?php

namespace App\Application\Operations;

use App\Models\Store;

class DetectOperationalAnomalies
{
    public function handle(Store $store): int
    {
        $triggeredFingerprints = [];

        foreach ($this->signals($store) as $signal) {
            if ($signal['current'] < $signal['minimum'] || ($signal['baseline'] > 0 && $signal['current'] < $signal['baseline'] * $signal['multiplier'])) {
                continue;
            }

            $fingerprint = hash('sha256', 'anomaly_detection|'.$signal['key']);
            $triggeredFingerprints[] = $fingerprint;
            $issue = $store->operationalIssues()->firstOrNew(['fingerprint' => $fingerprint]);
            $issue->fill([
                'source_tool' => 'anomaly_detection',
                'title' => $signal['title'],
                'reference' => $signal['key'],
                'status' => $issue->exists && in_array($issue->status, ['resolved', 'ignored'], true) ? 'open' : ($issue->status ?: 'open'),
                'priority' => $signal['priority'],
                'occurrences' => $issue->exists ? $issue->occurrences + 1 : 1,
                'first_seen_at' => $issue->first_seen_at ?? now(),
                'last_seen_at' => now(),
                'resolved_at' => null,
                'payload' => [
                    'current' => $signal['current'],
                    'baseline_average' => round($signal['baseline'], 2),
                    'window' => $signal['window'],
                    'detected_at' => now()->toIso8601String(),
                ],
            ]);
            $issue->save();
        }

        $store->operationalIssues()
            ->where('source_tool', 'anomaly_detection')
            ->whereIn('status', ['open', 'in_progress'])
            ->when($triggeredFingerprints !== [], fn ($query) => $query->whereNotIn('fingerprint', $triggeredFingerprints))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        return count($triggeredFingerprints);
    }

    /**
     * @return list<array{key:string,title:string,priority:string,current:int,baseline:float,minimum:int,multiplier:float,window:string}>
     */
    private function signals(Store $store): array
    {
        $hourAgo = now()->subHour();
        $dayAgo = now()->subDay();
        $eightDaysAgo = now()->subDays(8);

        return [
            [
                'key' => 'webhook_failures',
                'title' => 'Unusual spike in failed webhooks',
                'priority' => 'high',
                'current' => $store->webhookEvents()->where('status', 'failed')->where('occurred_at', '>=', $hourAgo)->count(),
                'baseline' => $store->webhookEvents()->where('status', 'failed')->whereBetween('occurred_at', [now()->subHours(25), $hourAgo])->count() / 24,
                'minimum' => 3,
                'multiplier' => 3.0,
                'window' => '1 hour',
            ],
            [
                'key' => 'audit_failures',
                'title' => 'Unusual spike in failed audits',
                'priority' => 'urgent',
                'current' => $store->runLogs()->where('status', 'error')->where('created_at', '>=', $dayAgo)->count(),
                'baseline' => $store->runLogs()->where('status', 'error')->whereBetween('created_at', [$eightDaysAgo, $dayAgo])->count() / 7,
                'minimum' => 2,
                'multiplier' => 2.5,
                'window' => '24 hours',
            ],
            [
                'key' => 'new_issues',
                'title' => 'Unusual spike in new operational issues',
                'priority' => 'high',
                'current' => $store->operationalIssues()->where('source_tool', '!=', 'anomaly_detection')->where('created_at', '>=', $dayAgo)->count(),
                'baseline' => $store->operationalIssues()->where('source_tool', '!=', 'anomaly_detection')->whereBetween('created_at', [$eightDaysAgo, $dayAgo])->count() / 7,
                'minimum' => 5,
                'multiplier' => 2.0,
                'window' => '24 hours',
            ],
        ];
    }
}
