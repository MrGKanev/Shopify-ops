<?php

namespace App\Http\Controllers;

use App\Application\Exports\CsvExporter;
use App\Application\Operations\SaveCarrierClaim;
use App\Domain\Orders\CarrierClaimLedger;
use App\Http\Requests\CarrierClaimCommunicationRequest;
use App\Http\Requests\CarrierClaimDocumentRequest;
use App\Http\Requests\CarrierClaimPaymentRequest;
use App\Http\Requests\CarrierClaimPolicyRequest;
use App\Http\Requests\CarrierClaimRequest;
use App\Models\CarrierClaim;
use App\Models\CarrierClaimDocument;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarrierClaimController extends Controller
{
    public function __construct(private readonly CarrierClaimLedger $ledger, private readonly SaveCarrierClaim $save) {}

    public function index(Request $request): View
    {
        $store = $this->resolveStore($request);
        $status = $request->string('status')->toString();
        $overdue = $request->boolean('overdue');
        $claims = $store->carrierClaims()->with('payments')
            ->when(in_array($status, CarrierClaim::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($overdue, fn ($query) => $query->whereIn('status', CarrierClaim::ACTIVE_STATUSES)->whereNotNull('follow_up_on')->whereDate('follow_up_on', '<', today()))
            ->orderByRaw('follow_up_on is null')->orderBy('follow_up_on')->latest('id')->limit(500)->get();
        $outstanding = [];
        $rows = $claims->map(function (CarrierClaim $claim) use (&$outstanding): array {
            $summary = $this->summary($claim);
            if ($claim->isActive()) {
                foreach ($summary as $kind) {
                    $outstanding[$claim->currency] = ($outstanding[$claim->currency] ?? 0) + ($kind['outstanding'] === null ? 0 : CarrierClaimLedger::toMicros($kind['outstanding']));
                }
            }

            return ['claim' => $claim, 'summary' => $summary];
        });

        return view('carrier-claims.index', ['rows' => $rows, 'status' => $status, 'overdue' => $overdue, 'statuses' => CarrierClaim::STATUSES, 'policies' => $store->carrierClaimPolicies()->orderBy('provider')->get(), 'outstanding' => array_map(CarrierClaimLedger::format(...), $outstanding)]);
    }

    public function create(Request $request): View
    {
        $store = $this->resolveStore($request);
        $issue = $request->integer('issue') > 0 ? $store->operationalIssues()->find($request->integer('issue')) : null;
        $claim = new CarrierClaim(['status' => 'draft', 'currency' => 'USD', 'operational_issue_id' => $issue?->getKey(), 'order_number' => $issue?->reference]);

        return view('carrier-claims.form', ['claim' => $claim, 'summary' => null, 'statuses' => CarrierClaim::STATUSES, 'policies' => $store->carrierClaimPolicies()->orderBy('provider')->get(), 'candidates' => []]);
    }

    public function store(CarrierClaimRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $claim = $this->save->handle($store, null, $this->attributes($request, $store), $this->authenticatedUser($request));
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['claim_id' => $claim->getKey()])->log('carrier_claim_create');

        return redirect()->route('carrier-claims.show', $claim->id)->with('status', __('Carrier claim saved.'));
    }

    public function show(Request $request, int $claim): View
    {
        $model = $this->resolveStore($request)->carrierClaims()->with(['payments', 'documents'])->findOrFail($claim);

        $store = $this->resolveStore($request);

        return view('carrier-claims.form', ['claim' => $model, 'summary' => $this->summary($model), 'statuses' => CarrierClaim::STATUSES, 'policies' => $store->carrierClaimPolicies()->orderBy('provider')->get(), 'candidates' => $this->save->ledgerCandidates($store, $model)]);
    }

    public function update(CarrierClaimRequest $request, int $claim): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $model = $store->carrierClaims()->findOrFail($claim);
        $this->save->handle($store, $model, $this->attributes($request, $store), $this->authenticatedUser($request));
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['claim_id' => $model->getKey()])->log('carrier_claim_update');

        return redirect()->route('carrier-claims.show', $model->id)->with('status', __('Carrier claim saved.'));
    }

    public function storePolicy(CarrierClaimPolicyRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $policy = $store->carrierClaimPolicies()->create($request->validated() + ['verified_by' => $request->user()?->getKey()]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['policy_id' => $policy->getKey()])->log('carrier_claim_policy_save');

        return redirect()->route('carrier-claims.index')->with('status', __('Policy saved.'));
    }

    public function destroyPolicy(Request $request, int $policy): RedirectResponse
    {
        $this->resolveStore($request)->carrierClaimPolicies()->findOrFail($policy)->delete();

        return redirect()->route('carrier-claims.index')->with('status', __('Policy deleted.'));
    }

    public function storePayment(CarrierClaimPaymentRequest $request, int $claim): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $model = $store->carrierClaims()->findOrFail($claim);
        $payment = $this->save->recordPayment($store, $model, $request->payment());
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['claim_id' => $model->getKey(), 'payment_id' => $payment->getKey(), 'kind' => $payment->kind])->log('carrier_claim_payment');

        return redirect()->route('carrier-claims.show', $model->id)->with('status', __('Reimbursement recorded.'));
    }

    public function storeCommunication(CarrierClaimCommunicationRequest $request, int $claim): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $this->save->addCommunication($store->carrierClaims()->findOrFail($claim), (string) $request->validated('text'), $this->authenticatedUser($request));

        return redirect()->route('carrier-claims.show', $claim)->with('status', __('Note added.'));
    }

    public function storeDocument(CarrierClaimDocumentRequest $request, int $claim): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $model = $store->carrierClaims()->findOrFail($claim);
        if ($model->documents()->count() >= CarrierClaimDocument::MAX_PER_CLAIM) {
            throw ValidationException::withMessages(['document' => __('A claim can hold at most :max documents.', ['max' => CarrierClaimDocument::MAX_PER_CLAIM])]);
        }
        $file = $request->file('document');
        $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : (string) $file->guessExtension();
        if (! array_key_exists($extension, CarrierClaimDocument::TYPES)) {
            throw ValidationException::withMessages(['document' => __('Only PDF, JPG, PNG or WebP files are accepted.')]);
        }
        $path = $file->storeAs(CarrierClaimDocument::directory($store->getKey(), $model->getKey()), Str::uuid().'.'.$extension, CarrierClaimDocument::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['document' => __('The file could not be stored. Try again.')]);
        }
        $document = $model->documents()->create(['store_id' => $store->getKey(), 'uploaded_by' => $request->user()?->getKey(), 'original_name' => CarrierClaimDocument::safeName($file->getClientOriginalName(), $extension), 'path' => $path, 'mime_type' => CarrierClaimDocument::TYPES[$extension], 'size' => (int) $file->getSize()]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['claim_id' => $model->getKey(), 'document_id' => $document->getKey(), 'name' => $document->original_name])->log('carrier_claim_document_upload');

        return redirect()->route('carrier-claims.show', $model->id)->with('status', __('Document uploaded.'));
    }

    public function downloadDocument(Request $request, int $claim, int $document): StreamedResponse
    {
        $model = $this->resolveStore($request)->carrierClaims()->findOrFail($claim)->documents()->findOrFail($document);
        abort_unless(Storage::disk(CarrierClaimDocument::DISK)->exists($model->path), 404);

        return Storage::disk(CarrierClaimDocument::DISK)->download($model->path, $model->original_name, ['Content-Type' => $model->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroyDocument(Request $request, int $claim, int $document): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $model = $store->carrierClaims()->findOrFail($claim)->documents()->findOrFail($document);
        $model->delete();
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['claim_id' => $claim, 'document_id' => $document, 'name' => $model->original_name])->log('carrier_claim_document_delete');

        return redirect()->route('carrier-claims.show', $claim)->with('status', __('Document deleted.'));
    }

    public function export(Request $request, CsvExporter $csv): StreamedResponse
    {
        $store = $this->resolveStore($request);
        $rows = $store->carrierClaims()->with('payments')->orderBy('id')->get()->map(function (CarrierClaim $claim): array {
            $summary = $this->summary($claim);

            return [$claim->provider, $claim->account ?? '', $claim->external_reference, $claim->order_number ?? '', $claim->tracking_number ?? '', $claim->status, $claim->currency,
                $summary['goods']['requested'] ?? '', $summary['goods']['approved'] ?? '', $summary['goods']['received'], $summary['goods']['outstanding'] ?? '',
                $summary['postage']['requested'] ?? '', $summary['postage']['approved'] ?? '', $summary['postage']['received'], $summary['postage']['outstanding'] ?? '',
                $claim->submitted_on?->toDateString() ?? '', $claim->decision_on?->toDateString() ?? '', $claim->follow_up_on?->toDateString() ?? '', $claim->deadline_on?->toDateString() ?? '', $claim->deadline_policy ?? '',
                $claim->dates_verified_at === null ? 'no' : 'yes', implode(' ', $claim->evidence ?? []), $claim->operational_issue_id ?? ''];
        });

        return $csv->download('carrier-claims-'.now()->format('Y-m-d').'.csv', ['Provider', 'Account', 'Reference', 'Order', 'Tracking', 'Status', 'Currency', 'Goods requested', 'Goods approved', 'Goods received', 'Goods outstanding', 'Postage requested', 'Postage approved', 'Postage received', 'Postage outstanding', 'Submitted', 'Decision', 'Follow-up', 'Deadline', 'Deadline policy', 'Dates verified', 'Evidence', 'Linked issue'], $rows);
    }

    /** @return array<string, mixed> */
    private function attributes(CarrierClaimRequest $request, Store $store): array
    {
        $attributes = $request->validated();
        $issueId = $attributes['operational_issue_id'] ?? null;
        if ($issueId !== null && ! $store->operationalIssues()->whereKey($issueId)->exists()) {
            throw ValidationException::withMessages(['operational_issue_id' => __('The linked issue was not found in this store.')]);
        }
        $attributes['dates_verified'] = $request->boolean('dates_verified');
        $policyId = $attributes['carrier_claim_policy_id'] ?? null;
        if ($policyId !== null) {
            $policy = $store->carrierClaimPolicies()->whereKey($policyId)->first()
                ?? throw ValidationException::withMessages(['carrier_claim_policy_id' => __('The selected policy was not found in this store.')]);
            $attributes['deadline_policy'] = filled($attributes['deadline_policy'] ?? null) ? $attributes['deadline_policy'] : $policy->label();
        }

        return $attributes;
    }

    /** @return array<string, array{requested: string|null, approved: string|null, received: string, outstanding: string|null, over_received: bool}> */
    private function summary(CarrierClaim $claim): array
    {
        return $this->ledger->summarize(
            array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $claim->only(['goods_requested', 'goods_approved', 'postage_requested', 'postage_approved'])),
            array_values($claim->payments->map(fn ($payment): array => ['kind' => $payment->kind, 'amount' => (string) $payment->amount])->all()),
        );
    }
}
