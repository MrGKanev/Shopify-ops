<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Application\Reports\ImportShippingBilling;
use App\Application\Reports\ShippingBillingCosts;
use App\Domain\Reports\ExactDecimalAmount;
use App\Domain\Reports\ShippingBillingAnalyzer;
use App\Http\Requests\ShippingBillingImportRequest;
use App\Http\Requests\UpdateShippingBillingEntryRequest;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShippingBillingController extends Controller
{
    public function index(Request $request, ShippingBillingCosts $costs, ShippingBillingAnalyzer $analyzer): View
    {
        $store = $this->resolveStore($request);
        $input = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'group_by' => ['nullable', 'in:carrier,service,package,skus']]);
        $start = $input['start_date'] ?? CarbonImmutable::now($store->shopTimezone())->subDays(30)->toDateString();
        $end = $input['end_date'] ?? CarbonImmutable::now($store->shopTimezone())->toDateString();
        $dimension = $input['group_by'] ?? 'carrier';
        $entries = $store->shippingBillingEntries()->where('account_fingerprint', ShippingBillingEntry::accountFingerprint($store))->whereDate('posted_at', '>=', $start)->whereDate('posted_at', '<=', $end)->latest('posted_at')->latest('id')->limit(501)->get();
        $data = $costs->forShipments($store, array_values($entries->take(500)->map(fn (ShippingBillingEntry $entry): string => $entry->shipment_id)->all()), $end);
        $groups = $data['groups'];
        $grouped = $analyzer->grouped($groups, $dimension);
        $truncated = $data['truncated'] || $entries->count() > 500;

        return view('shipping-billing.index', ['entries' => $entries->take(500), 'groups' => $groups, 'grouped' => $grouped, 'benchmarks' => $analyzer->benchmarks($groups), 'startDate' => $start, 'endDate' => $end, 'dimension' => $dimension, 'truncated' => $truncated, 'shipStationStoreNumber' => $store->store_number]);
    }

    public function export(Request $request, CsvExporter $csv): StreamedResponse
    {
        $store = $this->resolveStore($request);
        $input = $request->validate(['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $entries = $store->shippingBillingEntries()->where('account_fingerprint', ShippingBillingEntry::accountFingerprint($store))->whereDate('posted_at', '>=', $input['start_date'])->whereDate('posted_at', '<=', $input['end_date'])->orderBy('posted_at')->orderBy('id')->limit(5001)->get();
        if ($entries->count() > 5000) {
            throw ValidationException::withMessages(['export' => __('Narrow the selection to at most 5000 transactions before exporting.')]);
        }
        $rows = $entries->map(fn (ShippingBillingEntry $entry): array => [$entry->source_account, $entry->transaction_id, $entry->shipment_id, $entry->order_id, $entry->order_number, $entry->tracking_number, $entry->type, $entry->amount, $entry->currency, $entry->posted_at->toDateString(), $entry->carrier, $entry->service, $entry->package, $entry->skus, $entry->reason, $entry->statement_reference, $entry->dispute_status, (int) $entry->excluded, $entry->expected_refund, $entry->refund_due_at?->toDateString()]);

        return $csv->download('shipping-billing-'.$input['start_date'].'-to-'.$input['end_date'].'.csv', ['Source account', 'Transaction', 'Shipment ID', 'Order ID', 'Order', 'Tracking', 'Type', 'Amount', 'Currency', 'Posted', 'Carrier', 'Service', 'Package', 'SKU set', 'Reason', 'Statement', 'Dispute status', 'Excluded', 'Expected refund', 'Refund due'], $rows);
    }

    public function template(Request $request, CsvExporter $csv): StreamedResponse
    {
        $this->resolveStore($request);

        return $csv->download('shipping-billing-template.csv', ImportShippingBilling::HEADERS, []);
    }

    public function preview(ShippingBillingImportRequest $request, ImportShippingBilling $import): View
    {
        $store = $this->resolveStore($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $rows = $import->parse($file, $store);
        $sourceAccount = $request->string('source_account')->trim()->toString();
        $statement = $request->string('statement_reference')->trim()->toString();
        $token = (string) Str::uuid();
        Cache::put($this->previewKey($request, $store, $token), Crypt::encryptString(json_encode(['rows' => $rows, 'source_account' => $sourceAccount, 'statement' => $statement, 'file_hash' => hash('sha256', $file->getContent()), 'account_fingerprint' => ShippingBillingEntry::accountFingerprint($store), 'store_number' => (string) $store->store_number], JSON_THROW_ON_ERROR)), now()->addMinutes(10));

        return view('shipping-billing.preview', compact('rows', 'sourceAccount', 'statement', 'token'));
    }

    public function store(Request $request, ImportShippingBilling $import): RedirectResponse
    {
        $input = $request->validate(['token' => ['required', 'uuid']]);
        $store = $this->resolveStore($request);
        $key = $this->previewKey($request, $store, $input['token']);
        $counts = Cache::lock($key.':lock', 60)->block(3, function () use ($key, $store, $import): array {
            $encrypted = Cache::get($key);
            if (! is_string($encrypted)) {
                throw ValidationException::withMessages(['import' => __('Import preview expired. Upload the file again.')]);
            }
            $payload = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! is_string($payload['source_account'] ?? null) || ! is_string($payload['statement'] ?? null) || ! is_string($payload['file_hash'] ?? null)
                || ($payload['account_fingerprint'] ?? null) !== ShippingBillingEntry::accountFingerprint($store) || ($payload['store_number'] ?? null) !== (string) $store->store_number) {
                throw ValidationException::withMessages(['import' => __('The account changed after preview. Upload the file again.')]);
            }
            $rows = $this->previewRows($payload['rows'] ?? null);
            $counts = $import->handle($store, $rows, $payload['source_account'], $payload['statement'], $payload['file_hash']);
            Cache::forget($key);

            return $counts;
        });
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties($counts)->log('import_shipping_billing');

        return to_route('shipping-billing.index')->with('status', __('Imported :created transactions; :duplicates existing transactions skipped.', $counts));
    }

    public function update(UpdateShippingBillingEntryRequest $request, int $entry, ExactDecimalAmount $amounts): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $entry = $store->shippingBillingEntries()->where('account_fingerprint', ShippingBillingEntry::accountFingerprint($store))->findOrFail($entry);
        $values = $request->validated();
        $expected = $values['expected_refund'] ?? null;
        if ($expected !== null && ($entry->type !== 'label_charge' || $store->shippingBillingEntries()->where('account_fingerprint', $entry->account_fingerprint)->where('source_account', $entry->source_account)->where('shipment_id', $entry->shipment_id)->where('currency', $entry->currency)->where('excluded', false)->where('type', 'label_charge')->count() !== 1)) {
            throw ValidationException::withMessages(['expected_refund' => __('Set an expected refund only for an unambiguous single label charge.')]);
        }
        $entry->update([...$values, 'expected_refund' => $expected === null ? null : (string) ($amounts->parse($expected) ?? throw new \LogicException('Invalid expected refund.'))->toScale(6), 'refund_due_at' => $expected === null ? null : ($values['refund_due_at'] ?? null)]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['entry_id' => $entry->id, 'dispute_status' => $entry->dispute_status, 'excluded' => $entry->excluded])->log('review_shipping_billing');

        return back()->with('status', __('Billing review saved. Dispute decisions do not confirm received credits.'));
    }

    /** @return list<array<string, string>> */
    private function previewRows(mixed $rows): array
    {
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) > 0 && count($rows) <= 5000, 422);
        $result = [];
        foreach ($rows as $row) {
            abort_unless(is_array($row), 422);
            $clean = [];
            foreach ($row as $key => $value) {
                abort_unless(is_string($key) && is_string($value), 422);
                $clean[$key] = $value;
            }
            $result[] = $clean;
        }

        return $result;
    }

    private function previewKey(Request $request, Store $store, string $token): string
    {
        return 'shipping-billing-preview:'.$store->id.':'.$request->user()?->getAuthIdentifier().':'.$token;
    }
}
