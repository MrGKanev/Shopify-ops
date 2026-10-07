<?php

namespace App\Http\Controllers;

use App\Application\Orders\BuildRemediationPlan;
use App\Application\Orders\PrepareRemediationPreview;
use App\Http\Requests\ConfirmOrderRemediationRequest;
use App\Http\Requests\PreviewOrderRemediationRequest;
use App\Jobs\ExecuteOrderRemediation;
use App\Jobs\PrepareOrderRemediation;
use App\Models\RemediationRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class OrderRemediationController extends Controller
{
    public function create(Request $request): View
    {
        $numbers = $request->input('order_numbers', $request->input('order_number', ''));
        if (is_array($numbers)) {
            $numbers = implode("\n", array_filter($numbers, is_string(...)));
        }

        return view('orders.remediation', ['actions' => BuildRemediationPlan::ACTIONS, 'numbers' => is_string($numbers) ? $numbers : '', 'selectedAction' => $request->string('action')->toString()]);
    }

    public function preview(PreviewOrderRemediationRequest $request, PrepareRemediationPreview $prepare): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $group = (string) Str::uuid();
        $action = $request->validated('action');
        $options = $request->safe()->only(['tag', 'tag_id', 'hold_until']);
        $runs = [];
        foreach ($request->validated('order_numbers') as $number) {
            $runs[] = $store->remediationRuns()->create(['user_id' => $request->user()->id, 'group_uuid' => $group, 'order_number' => $number, 'action' => $action, 'status' => 'preparing', 'plan' => ['options' => $options], 'expires_at' => now()->addMinutes(15)]);
        }
        if (count($runs) === 1) {
            $prepare->handle($runs[0]);
        } else {
            try {
                Bus::batch(array_map(fn (RemediationRun $run): PrepareOrderRemediation => new PrepareOrderRemediation($run->id), $runs))->name('Remediation preview: '.$group)->allowFailures()->dispatch();
            } catch (Throwable $exception) {
                $store->remediationRuns()->where('group_uuid', $group)->where('status', 'preparing')->update(['status' => 'blocked', 'plan' => null, 'error_category' => $exception::class, 'result_message' => 'The preview could not be queued. Create a new preview.']);
            }
        }

        return redirect()->route('orders.remediation.show', $group);
    }

    public function show(Request $request, string $group): View
    {
        $runs = $this->resolveStore($request)->remediationRuns()->where('group_uuid', $group)->orderBy('id')->get();
        abort_if($runs->isEmpty(), 404);
        $canConfirm = $runs->contains(fn (RemediationRun $run): bool => $run->status === 'draft') && ! $runs->contains(fn (RemediationRun $run): bool => $run->status === 'preparing') && $runs->every(fn (RemediationRun $run): bool => $run->user_id === $request->user()->id && $run->expires_at->isFuture());

        return view('orders.remediation-preview', ['runs' => $runs, 'group' => $group, 'canConfirm' => $canConfirm, 'actions' => BuildRemediationPlan::ACTIONS]);
    }

    public function store(ConfirmOrderRemediationRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $group = $request->validated('group_uuid');
        $queued = DB::transaction(function () use ($store, $request, $group): array {
            $runs = $store->remediationRuns()->where('group_uuid', $group)->lockForUpdate()->get();
            abort_if($runs->isEmpty() || $runs->contains(fn (RemediationRun $run): bool => $run->user_id !== $request->user()->id), 404);
            abort_if($runs->contains(fn (RemediationRun $run): bool => $run->status === 'preparing'), 409, 'Wait for all order previews to finish.');
            $drafts = $runs->where('status', 'draft');
            abort_if($drafts->contains(fn (RemediationRun $run): bool => $run->expires_at->isPast()), 409, 'The preview expired. Review the current orders again.');
            foreach ($drafts as $run) {
                $run->update(['status' => 'queued']);
                activity('operator-actions')->causedBy($request->user())->performedOn($run)->withProperties(['store_id' => $store->id, 'action' => $run->action])->log('remediation_queued');
            }

            return $drafts->modelKeys();
        });
        if ($queued !== []) {
            try {
                $batch = Bus::batch(array_map(fn (int $id): ExecuteOrderRemediation => new ExecuteOrderRemediation($id), $queued))->name('Order remediation: '.$group)->allowFailures()->dispatch();
                $store->remediationRuns()->whereIn('id', $queued)->update(['batch_id' => $batch->id]);
            } catch (Throwable $exception) {
                $store->remediationRuns()->whereIn('id', $queued)->where('status', 'queued')->update(['status' => 'failed', 'error_category' => $exception::class, 'result_message' => 'The job could not be queued. Create a new preview.', 'finished_at' => now()]);

                return redirect()->route('orders.remediation.show', $group)->withErrors(['remediation' => __('The job could not be queued.')]);
            }
        }

        return redirect()->route('orders.remediation.show', $group)->with('status', __('Remediation queued.'));
    }
}
