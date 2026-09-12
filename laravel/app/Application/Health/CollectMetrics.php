<?php

namespace App\Application\Health;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class CollectMetrics
{
    public function handle(): string
    {
        $lines = [];

        $lines[] = '# HELP checker_runs_total Report and audit runs recorded, by tool and status.';
        $lines[] = '# TYPE checker_runs_total counter';
        foreach ($this->countBy('run_logs', ['tool', 'status']) as $row) {
            $lines[] = sprintf('checker_runs_total{tool="%s",status="%s"} %d', $row->tool, $row->status, $row->total);
        }

        $lines[] = '# HELP checker_notification_deliveries_total Notification delivery attempts, by channel and status.';
        $lines[] = '# TYPE checker_notification_deliveries_total counter';
        foreach ($this->countBy('notification_deliveries', ['channel', 'status']) as $row) {
            $lines[] = sprintf('checker_notification_deliveries_total{channel="%s",status="%s"} %d', $row->channel, $row->status, $row->total);
        }

        $lines[] = '# HELP checker_failed_jobs_total Queue jobs currently in the failed_jobs table.';
        $lines[] = '# TYPE checker_failed_jobs_total gauge';
        $lines[] = sprintf('checker_failed_jobs_total %d', DB::table('failed_jobs')->count());

        $lines[] = '# HELP checker_queue_pending_jobs Queue jobs waiting to be processed.';
        $lines[] = '# TYPE checker_queue_pending_jobs gauge';
        $lines[] = sprintf('checker_queue_pending_jobs %d', DB::table('jobs')->count());

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $columns
     * @return Collection<int, stdClass>
     */
    private function countBy(string $table, array $columns)
    {
        return DB::table($table)->selectRaw(implode(', ', $columns).', count(*) as total')->groupBy($columns)->get();
    }
}
