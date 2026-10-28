<?php

namespace App\Application\Health;

use App\IssueStatus;
use App\Listeners\AlertOnOperationalFailure;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\WaitTimeCalculator;

class CheckOperationalAlerts
{
    public function __construct(
        private readonly AlertOnOperationalFailure $alerts,
        private readonly WaitTimeCalculator $waitTimes,
    ) {}

    public function handle(): void
    {
        $this->alertOnce('queue_latency', $this->queueLatencySeconds() > 300, 'Queue wait exceeds 5 minutes.');

        $heartbeat = Cache::get('health:checks:schedule:latestHeartbeatAt');
        $this->alertOnce(
            'scheduler_absent',
            ! is_numeric($heartbeat) || now()->getTimestamp() - (int) $heartbeat > 300,
            'Scheduler heartbeat is older than 5 minutes.',
        );

        DB::table('run_logs')
            ->select('tool')
            ->selectRaw('count(*) as failures')
            ->where('status', 'error')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->groupBy('tool')
            ->havingRaw('count(*) >= 3')
            ->get()
            ->each(fn (object $run): bool => $this->alertOnce(
                'api_failures:'.$run->tool,
                true,
                "{$run->failures} failures in 15 minutes.",
            ));

        $this->alertOnLateImports();
    }

    /**
     * Expected orders still absent from ShipStation after the store's grace window. A quiet store without absent orders never alerts.
     */
    private function alertOnLateImports(): void
    {
        Store::query()->whereHas('operationalIssues', fn ($query) => $query->where('source_tool', 'run_audit')->whereIn('status', IssueStatus::active()))->each(function (Store $store): void {
            $late = $store->operationalIssues()->where('source_tool', 'run_audit')->whereIn('status', IssueStatus::active())->where('first_seen_at', '<=', now()->subHours($store->importGraceHours()))->count();
            $this->alertOnce('import_lag:'.$store->getKey(), $late > 0, "{$store->label}: {$late} expected orders have been absent from ShipStation for over {$store->importGraceHours()} hours.", 360);
        });
    }

    private function queueLatencySeconds(): int
    {
        if (config('queue.default') === 'redis') {
            return (int) max($this->waitTimes->calculate() ?: [0]);
        }

        if (config('queue.default') === 'database') {
            $oldest = DB::table('jobs')->min('available_at');

            return is_numeric($oldest) ? max(0, now()->getTimestamp() - (int) $oldest) : 0;
        }

        return 0;
    }

    private function alertOnce(string $category, bool $failed, string $summary, int $minutes = 15): bool
    {
        if (! $failed || ! Cache::add('operational-alert:'.hash('sha256', $category), true, now()->addMinutes($minutes))) {
            return false;
        }

        $this->alerts->send($category, $summary);

        return true;
    }
}
