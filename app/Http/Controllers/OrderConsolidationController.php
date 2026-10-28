<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Application\Orders\LoadConsolidationReview;
use App\Domain\Orders\OrderConsolidationPlanner;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\ConsolidationDecision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrderConsolidationController extends Controller
{
    public function show(Request $request, LoadConsolidationReview $review, OrderConsolidationPlanner $planner): View
    {
        $input = $this->input($request);
        $store = $this->resolveStore($request);
        $result = null;
        $failure = null;
        if ($request->boolean('generate')) {
            [$result, $failure] = $this->load($review, $store, $input['window']);
            foreach ($result['groups'] ?? [] as $groupIndex => $group) {
                foreach ($group['bundles'] as $bundleIndex => $bundle) {
                    $result['groups'][$groupIndex]['bundles'][$bundleIndex]['savings'] = $planner->savings(count($bundle['orders']), $input['separate'], $input['combined']);
                }
            }
        }

        return view('print-queue.consolidation', ['result' => $result, 'failure' => $failure, 'window' => $input['window'], 'separate' => $input['separate'], 'combined' => $input['combined'], 'max' => LoadConsolidationReview::MAX_GROUPS]);
    }

    public function export(Request $request, LoadConsolidationReview $review, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        $input = $this->input($request);
        [$result, $failure] = $this->load($review, $this->resolveStore($request), $input['window']);
        if ($result === null) {
            return to_route('print-queue.consolidation', ['window' => $input['window']])->withErrors(['consolidation' => __($failure === 'configuration' ? 'ShipStation is not configured completely for this store.' : 'The consolidation review could not be completed. Try again.')]);
        }
        $rows = [];
        foreach ($result['groups'] as $group) {
            foreach ($group['members'] as $member) {
                $partners = '';
                foreach ($group['bundles'] as $bundle) {
                    if (in_array($member['number'], $bundle['orders'], true)) {
                        $partners = implode(' + ', $bundle['orders']);
                    }
                }
                foreach ($member['lines'] as $line) {
                    $rows[] = [$group['id'], $group['email'], $member['number'], $partners, $member['state'], implode('; ', $member['reasons']), $line['sku'], $line['name'], $line['quantity']];
                }
            }
        }

        return $csv->download('consolidation-review-'.now()->toDateString().'.csv', ['Group', 'Customer email', 'Order', 'Ships together with', 'State', 'Reasons', 'SKU', 'Item', 'Quantity'], $rows);
    }

    public function decide(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'order_number' => ['required', 'string', 'max:64', 'regex:/\A#?[a-zA-Z0-9_-]+\z/'],
            'decision' => ['required', Rule::in([...ConsolidationDecision::DECISIONS, 'clear'])],
            'window' => ['nullable', 'integer', 'between:0,30'],
        ]);
        $store = $this->resolveStore($request);
        $number = ltrim($input['order_number'], '#');
        if ($input['decision'] === 'clear') {
            $store->consolidationDecisions()->where('order_number', $number)->delete();
        } else {
            $store->consolidationDecisions()->updateOrCreate(['order_number' => $number], ['decision' => $input['decision'], 'user_id' => $request->user()?->getAuthIdentifier()]);
        }
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['order_number' => $number, 'decision' => $input['decision']])->log('consolidation_decision');

        return to_route('print-queue.consolidation', array_filter(['generate' => 1, 'window' => $input['window'] ?? null], fn (mixed $value): bool => $value !== null))->with('status', __('Decision saved.'));
    }

    /** @return array{window: int, separate: string|null, combined: string|null} */
    private function input(Request $request): array
    {
        $validated = $request->validate(['window' => ['nullable', 'integer', 'between:0,30'], 'separate' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'combined' => ['nullable', 'numeric', 'min:0', 'max:100000']]);

        return ['window' => (int) ($validated['window'] ?? 3), 'separate' => isset($validated['separate']) ? (string) $validated['separate'] : null, 'combined' => isset($validated['combined']) ? (string) $validated['combined'] : null];
    }

    /** @return array{0: array<string, mixed>|null, 1: string|null} */
    private function load(LoadConsolidationReview $review, mixed $store, int $window): array
    {
        try {
            return [$review->handle($store, $window), null];
        } catch (LogicException) {
            return [null, 'configuration'];
        } catch (Throwable $exception) {
            Log::warning('Consolidation review failed.', ['exception_type' => $exception::class, 'status' => $exception instanceof IntegrationException ? $exception->status : null]);

            return [null, 'lookup'];
        }
    }
}
