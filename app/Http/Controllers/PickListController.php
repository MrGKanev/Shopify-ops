<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Application\Orders\LoadCustomerContext;
use App\Application\Orders\LoadPickList;
use App\Domain\Orders\PickListBuilder;
use App\Http\Requests\PickListTemplateRequest;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\PickListSnapshot;
use App\Models\PickListTemplate;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PickListController extends Controller
{
    public function show(Request $request, LoadPickList $loader, LoadCustomerContext $contexts): View
    {
        $store = $this->resolveStore($request);
        $template = $this->template($request, $store);
        $result = null;
        $failure = null;
        $customerContexts = [];
        $numbers = $this->queuedNumbers($store);
        if ($request->boolean('generate') && $numbers !== []) {
            try {
                $result = $loader->handle($store, $numbers, $template->sort);
                if ($request->boolean('customer_context')) {
                    try {
                        $customerContexts = $contexts->handle($store, $numbers)['rows'];
                    } catch (Throwable) {
                        $customerContexts = [];
                    }
                }
            } catch (LogicException) {
                $failure = 'configuration';
            } catch (Throwable $exception) {
                $failure = 'lookup';
                Log::warning('Pick list lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : null]);
            }
        }

        return view('print-queue.pick-list', ['templates' => $store->pickListTemplates()->orderBy('name')->get(), 'template' => $template, 'result' => $result, 'failure' => $failure, 'queued' => count($numbers), 'customerContexts' => $customerContexts, 'columnLabels' => PickListBuilder::COLUMNS, 'snapshots' => $store->pickListSnapshots()->latest('id')->limit(PickListSnapshot::MAX_PER_STORE)->get(['id', 'template_name', 'created_at', 'payload'])]);
    }

    public function export(Request $request, LoadPickList $loader, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $template = $this->template($request, $store);
        $numbers = $this->queuedNumbers($store);
        if ($numbers === []) {
            return redirect()->route('print-queue.pick-list')->withErrors(['pick_list' => __('The print queue is empty.')]);
        }
        try {
            $result = $loader->handle($store, $numbers, $template->sort);
        } catch (Throwable $exception) {
            Log::warning('Pick list export failed.', ['exception_type' => $exception::class]);

            return redirect()->route('print-queue.pick-list')->withErrors(['pick_list' => __('The pick list could not be loaded. Try again.')]);
        }

        return $this->download($csv, $template->columns, $result['lines'], 'pick-list-'.now()->format('Y-m-d').'.csv');
    }

    /** Stores the pick list as it is generated now; the snapshot never changes when the template or ShipStation data does. */
    public function storeSnapshot(Request $request, LoadPickList $loader): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $template = $this->template($request, $store);
        $numbers = $this->queuedNumbers($store);
        if ($numbers === []) {
            return redirect()->route('print-queue.pick-list')->withErrors(['pick_list' => __('The print queue is empty.')]);
        }
        if ($store->pickListSnapshots()->count() >= PickListSnapshot::MAX_PER_STORE) {
            throw ValidationException::withMessages(['pick_list' => __('A store can keep at most :max pick-list snapshots. Delete one first.', ['max' => PickListSnapshot::MAX_PER_STORE])]);
        }
        try {
            $result = $loader->handle($store, $numbers, $template->sort);
        } catch (Throwable $exception) {
            Log::warning('Pick list snapshot failed.', ['exception_type' => $exception::class]);

            return redirect()->route('print-queue.pick-list')->withErrors(['pick_list' => __('The pick list could not be loaded. Try again.')]);
        }
        $snapshot = $store->pickListSnapshots()->create(['created_by' => $request->user()?->getKey(), 'template_name' => $template->exists ? $template->name : null, 'columns' => $template->columns, 'sort' => $template->sort, 'size' => $template->size, 'payload' => $result]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['snapshot_id' => $snapshot->getKey()])->log('pick_list_snapshot_save');

        return redirect()->route('print-queue.pick-list.snapshots.show', $snapshot->id)->with('status', __('Pick-list snapshot saved.'));
    }

    public function showSnapshot(Request $request, int $snapshot): View
    {
        $model = $this->resolveStore($request)->pickListSnapshots()->findOrFail($snapshot);

        return view('print-queue.pick-list-snapshot', ['snapshot' => $model, 'columnLabels' => PickListBuilder::COLUMNS]);
    }

    public function exportSnapshot(Request $request, CsvExporter $csv, int $snapshot): StreamedResponse
    {
        $model = $this->resolveStore($request)->pickListSnapshots()->findOrFail($snapshot);

        return $this->download($csv, $model->columns, $model->payload['lines'], 'pick-list-snapshot-'.$model->id.'.csv');
    }

    public function destroySnapshot(Request $request, int $snapshot): RedirectResponse
    {
        $this->resolveStore($request)->pickListSnapshots()->findOrFail($snapshot)->delete();

        return redirect()->route('print-queue.pick-list')->with('status', __('Pick-list snapshot deleted.'));
    }

    public function store(PickListTemplateRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $input = $request->validated();
        $template = $store->pickListTemplates()->updateOrCreate(['name' => trim($input['name'])], ['columns' => array_values($input['columns']), 'sort' => $input['sort'], 'size' => $input['size']]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['template' => $template->name])->log('pick_list_template_save');

        return redirect()->route('print-queue.pick-list', ['template' => $template->id])->with('status', __('Pick-list template saved.'));
    }

    public function destroy(Request $request, int $template): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $store->pickListTemplates()->findOrFail($template)->delete();

        return redirect()->route('print-queue.pick-list')->with('status', __('Pick-list template deleted.'));
    }

    /**
     * @param  list<string>  $templateColumns
     * @param  list<array{sku: string, name: string, options: string, location: string, image: string, quantity: int, allocations: list<array{order: string, quantity: int}>}>  $lines
     */
    private function download(CsvExporter $csv, array $templateColumns, array $lines, string $filename): StreamedResponse
    {
        $columns = array_values(array_filter($templateColumns, fn (string $column): bool => $column !== 'image'));
        $rows = array_map(fn (array $line): array => array_map(fn (string $column): int|string => match ($column) {
            'quantity' => $line['quantity'],
            'orders' => implode('; ', array_map(fn (array $allocation): string => $allocation['order'].' x'.$allocation['quantity'], $line['allocations'])),
            'location' => $line['location'],
            'sku' => $line['sku'],
            'name' => $line['name'],
            'options' => $line['options'],
            default => '',
        }, $columns), $lines);

        return $csv->download($filename, array_map(fn (string $column): string => PickListBuilder::COLUMNS[$column], $columns), $rows);
    }

    private function template(Request $request, Store $store): PickListTemplate
    {
        $id = $request->query('template');

        return (is_string($id) && ctype_digit($id) ? $store->pickListTemplates()->find((int) $id) : null)
            ?? new PickListTemplate(['name' => '', 'columns' => PickListBuilder::DEFAULT_COLUMNS, 'sort' => 'location', 'size' => 'normal']);
    }

    /** @return list<string> */
    private function queuedNumbers(Store $store): array
    {
        return array_values($store->printQueueItems()->oldest()->pluck('order_number')->map(fn (mixed $number): string => (string) $number)->all());
    }
}
