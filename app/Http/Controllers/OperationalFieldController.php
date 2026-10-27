<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Application\Orders\LoadOperationalFields;
use App\Domain\Orders\OperationalFieldFilterMatcher;
use App\Http\Requests\OperationalFieldFilterRequest;
use App\Http\Requests\OperationalFieldRequest;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\OperationalFieldFilter;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OperationalFieldController extends Controller
{
    public function index(Request $request, LoadOperationalFields $loader, OperationalFieldFilterMatcher $matcher): View
    {
        $store = $this->resolveStore($request);
        $filter = $this->savedFilter($request, $store);
        $numbers = $this->queuedNumbers($store);
        $result = null;
        $failure = null;
        if ($request->boolean('generate') && $numbers !== []) {
            try {
                $result = $this->filtered($loader->handle($store, $numbers), $filter, $matcher);
            } catch (LogicException) {
                $failure = 'configuration';
            } catch (Throwable $exception) {
                $failure = 'lookup';
                Log::warning('Operational field lookup failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : null]);
            }
        }

        return view('operational-fields.index', ['fields' => $store->operationalFields()->orderBy('alias')->get(), 'result' => $result, 'failure' => $failure, 'queued' => count($numbers), 'filters' => $store->operationalFieldFilters()->with('field')->orderBy('name')->get(), 'savedFilter' => $filter]);
    }

    public function store(OperationalFieldRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $input = $request->validated();
        $field = $store->operationalFields()->updateOrCreate(['alias' => trim($input['alias'])], ['source_type' => $input['source_type'], 'source' => $input['source'], 'required' => $request->boolean('required')]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['alias' => $field->alias, 'source' => $field->source])->log('operational_field_save');

        return redirect()->route('operational-fields.index')->with('status', __('Operational field saved.'));
    }

    public function destroy(Request $request, int $field): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $store->operationalFields()->findOrFail($field)->delete();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['field_id' => $field])->log('operational_field_delete');

        return redirect()->route('operational-fields.index')->with('status', __('Operational field deleted.'));
    }

    public function export(Request $request, LoadOperationalFields $loader, CsvExporter $csv, OperationalFieldFilterMatcher $matcher): StreamedResponse|RedirectResponse
    {
        $store = $this->resolveStore($request);
        $filter = $this->savedFilter($request, $store);
        $numbers = $this->queuedNumbers($store);
        if ($numbers === []) {
            return redirect()->route('operational-fields.index')->withErrors(['operational_fields' => __('The print queue is empty.')]);
        }
        try {
            $result = $this->filtered($loader->handle($store, $numbers), $filter, $matcher);
        } catch (Throwable $exception) {
            Log::warning('Operational field export failed.', ['exception_type' => $exception::class]);

            return redirect()->route('operational-fields.index')->withErrors(['operational_fields' => __('The operational fields could not be loaded. Try again.')]);
        }
        $headers = ['Order', 'SKU', 'Item', 'Qty'];
        foreach ($result['fields'] as $field) {
            $headers[] = $field['alias'];
            $headers[] = $field['alias'].' (status)';
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            foreach ($row['items'] ?: [['sku' => '', 'name' => '', 'quantity' => 0]] as $item) {
                $line = [$row['order'], $item['sku'], $item['name'], $item['quantity']];
                foreach ($row['fields'] as $field) {
                    $line[] = $field['value'];
                    $line[] = $field['state'];
                }
                $rows[] = $line;
            }
        }

        return $csv->download('operational-fields-'.now()->format('Y-m-d').'.csv', $headers, $rows);
    }

    public function storeFilter(OperationalFieldFilterRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $input = $request->validated();
        $field = $store->operationalFields()->findOrFail((int) $input['field']);
        $filter = $store->operationalFieldFilters()->updateOrCreate(['name' => trim($input['name'])], ['operational_field_id' => $field->id, 'condition' => $input['condition'], 'text' => $input['condition'] === 'contains' ? trim((string) $input['text']) : null]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['filter' => $filter->name, 'alias' => $field->alias])->log('operational_field_filter_save');

        return redirect()->route('operational-fields.index', ['filter' => $filter->id])->with('status', __('Filter saved.'));
    }

    public function destroyFilter(Request $request, int $filter): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $store->operationalFieldFilters()->findOrFail($filter)->delete();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['filter_id' => $filter])->log('operational_field_filter_delete');

        return redirect()->route('operational-fields.index')->with('status', __('Filter deleted.'));
    }

    private function savedFilter(Request $request, Store $store): ?OperationalFieldFilter
    {
        $id = $request->integer('filter');

        return $id > 0 ? $store->operationalFieldFilters()->with('field')->findOrFail($id) : null;
    }

    /**
     * Applies the saved filter to rows and findings alike, so the page and the CSV never disagree.
     *
     * @param  array{rows: list<array{order: string, items: list<array{sku: string, name: string, quantity: int}>, fields: list<array{alias: string, state: string, value: string}>}>, findings: list<array{order: string, alias: string, state: string}>, missing: list<string>, ambiguous: list<string>, fields: list<array{alias: string, required: bool}>}  $result
     * @return array<string, mixed>
     */
    private function filtered(array $result, ?OperationalFieldFilter $filter, OperationalFieldFilterMatcher $matcher): array
    {
        $result['total'] = count($result['rows']);
        if ($filter === null) {
            return $result;
        }
        $result['rows'] = array_values(array_filter($result['rows'], fn (array $row): bool => $matcher->matchesFilter($row, $filter)));
        $shown = array_column($result['rows'], 'order');
        $result['findings'] = array_values(array_filter($result['findings'], fn (array $finding): bool => in_array($finding['order'], $shown, true)));

        return $result;
    }

    /** @return list<string> */
    private function queuedNumbers(Store $store): array
    {
        return array_values($store->printQueueItems()->oldest()->pluck('order_number')->map(fn (mixed $number): string => (string) $number)->all());
    }
}
