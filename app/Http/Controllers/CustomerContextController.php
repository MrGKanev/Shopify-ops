<?php

namespace App\Http\Controllers;

use App\Application\Orders\ListOpenStoreOrderNumbers;
use App\Application\Orders\LoadCustomerContext;
use App\Domain\Orders\CustomerFulfillmentContext;
use App\Http\Requests\CustomerContextFilterRequest;
use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Throwable;

class CustomerContextController extends Controller
{
    public function show(Request $request, LoadCustomerContext $loader, CustomerFulfillmentContext $context, ListOpenStoreOrderNumbers $openOrders): View
    {
        $validated = $request->validate([
            'rule' => ['nullable', 'string', 'in:'.implode(',', CustomerFulfillmentContext::RULES)],
            'vip' => ['nullable', 'integer', 'min:2', 'max:1000'],
            'source' => ['nullable', 'string', 'in:queue,open'],
        ]);
        $store = $this->resolveStore($request);
        $saved = $request->integer('filter') > 0 ? $store->customerContextFilters()->find($request->integer('filter')) : null;
        $rule = $validated['rule'] ?? $saved->rule ?? 'all';
        $vipAt = (int) ($validated['vip'] ?? $saved->vip_at ?? 5);
        $milestones = array_map('intval', $saved->milestones ?? CustomerFulfillmentContext::MILESTONES);
        $source = $validated['source'] ?? 'queue';
        $numbers = $source === 'queue' ? array_values($store->printQueueItems()->oldest()->pluck('order_number')->map(fn (mixed $number): string => (string) $number)->all()) : [];
        $openTotal = null;
        $result = null;
        $failure = null;
        if ($request->boolean('generate') && ($source === 'open' || $numbers !== [])) {
            try {
                if ($source === 'open') {
                    $open = $openOrders->handle($store);
                    $numbers = $open['numbers'];
                    $openTotal = $open['total'];
                }
                $result = $loader->handle($store, $numbers, $vipAt, $milestones);
                $result['total'] = count($result['rows']);
                $result['rows'] = array_values(array_filter($result['rows'], fn (array $row): bool => $context->matches($row, $rule)));
            } catch (LogicException) {
                $failure = 'configuration';
            } catch (Throwable $exception) {
                $failure = 'lookup';
                Log::warning('Customer context lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : null]);
            }
        }

        return view('print-queue.customer-context', ['result' => $result, 'failure' => $failure, 'queued' => $source === 'queue' ? count($numbers) : $store->printQueueItems()->count(), 'source' => $source, 'openTotal' => $openTotal, 'openMax' => ListOpenStoreOrderNumbers::MAX_ORDERS, 'rule' => $rule, 'vipAt' => $vipAt, 'milestones' => $milestones, 'filters' => $store->customerContextFilters()->orderBy('name')->get(), 'savedFilter' => $saved, 'max' => LoadCustomerContext::MAX_CUSTOMERS]);
    }

    public function storeFilter(CustomerContextFilterRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $input = $request->validated();
        $milestones = array_values(array_unique(array_map('intval', $input['milestones'] ?? [])));
        sort($milestones);
        $filter = $store->customerContextFilters()->updateOrCreate(['name' => trim($input['name'])], ['rule' => $input['rule'], 'vip_at' => (int) $input['vip'], 'milestones' => $milestones]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['filter' => $filter->name])->log('customer_context_filter_save');

        return redirect()->route('print-queue.customer-context', ['filter' => $filter->id])->with('status', __('Filter saved.'));
    }

    public function destroyFilter(Request $request, int $filter): RedirectResponse
    {
        $this->resolveStore($request)->customerContextFilters()->findOrFail($filter)->delete();

        return redirect()->route('print-queue.customer-context')->with('status', __('Filter deleted.'));
    }
}
