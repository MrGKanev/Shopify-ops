<?php

namespace App\Jobs;

use App\Application\Orders\PrepareRemediationPreview;
use App\Models\RemediationRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PrepareOrderRemediation implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $runId) {}

    public function handle(PrepareRemediationPreview $prepare): void
    {
        $run = RemediationRun::query()->with(['store', 'user'])->findOrFail($this->runId);
        if ($run->status !== 'preparing') {
            return;
        }
        if ($this->batch()?->cancelled()) {
            $run->update(['status' => 'cancelled', 'result_message' => 'Preview batch cancelled.']);

            return;
        }
        Cache::lock('remediation-preview:'.$run->id, 270)->block(5, function () use ($prepare, $run): void {
            $prepare->handle($run->refresh());
        });
    }

    public function failed(?Throwable $exception): void
    {
        RemediationRun::whereKey($this->runId)->where('status', 'preparing')->update(['status' => 'blocked', 'plan' => null, 'error_category' => $exception === null ? 'unknown' : $exception::class, 'result_message' => 'The preview could not be prepared. Create a new preview.']);
    }
}
