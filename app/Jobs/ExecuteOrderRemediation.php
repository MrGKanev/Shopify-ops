<?php

namespace App\Jobs;

use App\Application\Orders\ExecuteRemediation;
use App\Domain\Orders\RemediationUnavailable;
use App\Models\RemediationRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ExecuteOrderRemediation implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(ExecuteRemediation $execute): void
    {
        $run = RemediationRun::query()->with(['store', 'user'])->findOrFail($this->runId);
        if ($run->status !== 'queued') {
            return;
        }
        if ($this->batch()?->cancelled()) {
            $run->update(['status' => 'cancelled', 'finished_at' => now(), 'result_message' => 'Batch cancelled.']);

            return;
        }
        Cache::lock('order-remediation:'.$run->store_id.':'.($run->shopify_id ?? $run->order_number), 330)->block(5, function () use ($run, $execute): void {
            if (RemediationRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'running']) !== 1) {
                return;
            }
            try {
                if ($run->user === null || ! Gate::forUser($run->user)->allows('run-audits') || ! $run->user->stores()->whereKey($run->store_id)->exists()) {
                    throw new RemediationUnavailable('The operator no longer has access to this store.');
                }
                $execute->handle($run);
                $run->update(['status' => 'completed', 'finished_at' => now(), 'result_message' => $run->action === 'refresh_import' ? 'Import refresh requested. Wait for import, then review the missing order again before pushing.' : 'Changes confirmed by the integrations.']);
                $this->log($run, 'remediation_completed');
            } catch (Throwable $exception) {
                $message = $exception instanceof RemediationUnavailable ? $exception->getMessage() : 'The integration rejected or could not confirm the change. Review the remote state before trying a new preview.';
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error_category' => $exception::class, 'result_message' => $message]);
                $this->log($run, 'remediation_failed');
                $this->fail($exception);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $run = RemediationRun::find($this->runId);
        if ($run !== null && in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_category' => $exception === null ? 'unknown' : $exception::class, 'result_message' => 'Execution stopped. Review the remote state before trying a new preview.']);
            $this->log($run, 'remediation_failed');
        }
    }

    private function log(RemediationRun $run, string $event): void
    {
        activity('operator-actions')->causedBy($run->user)->performedOn($run)
            ->withProperties(['store_id' => $run->store_id, 'action' => $run->action, 'completed_steps' => $run->completed_steps, 'status' => $run->status])->log($event);
    }
}
