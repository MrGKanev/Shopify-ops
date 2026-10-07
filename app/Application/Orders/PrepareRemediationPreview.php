<?php

namespace App\Application\Orders;

use App\Domain\Orders\RemediationUnavailable;
use App\Models\RemediationRun;
use Illuminate\Support\Facades\Gate;
use Throwable;

class PrepareRemediationPreview
{
    public function __construct(private readonly BuildRemediationPlan $plans) {}

    public function handle(RemediationRun $run): void
    {
        if ($run->status !== 'preparing') {
            return;
        }
        try {
            if ($run->user === null || ! Gate::forUser($run->user)->allows('run-audits') || ! $run->user->stores()->whereKey($run->store_id)->exists()) {
                throw new RemediationUnavailable('The operator no longer has access to this store.');
            }
            $plan = $this->plans->preview($run->store, $run->order_number, $run->action, $run->plan['options']);
            $run->update(['status' => 'draft', 'shopify_id' => $plan['shopify_id'], 'plan' => $plan, 'expires_at' => now()->addMinutes(15)]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'blocked', 'plan' => null, 'result_message' => $exception instanceof RemediationUnavailable ? $exception->getMessage() : 'The current integration data could not be loaded. Check credentials, scopes and API health.', 'error_category' => $exception::class]);
        }
    }
}
