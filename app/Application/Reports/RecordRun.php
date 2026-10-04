<?php

namespace App\Application\Reports;

use App\Application\Notifications\ReportNotifier;
use App\Models\RunLog;
use App\Models\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;

class RecordRun
{
    public function __construct(private readonly ReportNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $attributes  may include an 'attachment' key shaped
     *                                            array{headers: list<string>, rows: list<list<bool|float|int|string|null>>}
     *                                            for the immediate-mode email CSV attachment
     */
    public function handle(Store $store, array $attributes): RunLog
    {
        $attachment = $attributes['attachment'] ?? null;
        $run = $store->runLogs()->create(Arr::except($attributes, ['attachment']));
        Context::add([
            'run_id' => $run->getKey(),
            'store_id' => $store->getKey(),
            'tool' => (string) ($attributes['tool'] ?? 'unknown'),
            'run_status' => (string) ($attributes['status'] ?? 'unknown'),
        ]);
        $oldIds = $store->runLogs()->latest('id')->skip(500)->take(500)->pluck('id');
        if ($oldIds->isNotEmpty()) {
            RunLog::whereKey($oldIds)->delete();
        }
        $tool = (string) ($attributes['tool'] ?? '');
        if (($attributes['status'] ?? '') !== 'error') {
            $rows = (int) ($attributes['rows_found'] ?? 0);
            if ($tool !== 'run_audit') {
                $this->notifier->scanFinished($store, $tool !== '' ? $tool : 'scan', $rows, (float) ($attributes['duration_seconds'] ?? 0.0));
            }
            $this->notifier->emailImmediately($store, $tool, $rows, isset($attributes['start_date']) ? (string) $attributes['start_date'] : null, isset($attributes['end_date']) ? (string) $attributes['end_date'] : null, $attachment);
        }

        return $run;
    }
}
