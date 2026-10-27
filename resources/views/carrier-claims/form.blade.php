@extends('layouts.app')

@section('content')
    @php
        $input = 'mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950';
        $date = fn (string $field): string => old($field, optional($claim->{$field})->format('Y-m-d') ?? '');
        $value = fn (string $field): string => (string) old($field, $claim->{$field} ?? '');
        $statusText = fn (string $status): string => __(ucfirst(str_replace('_', ' ', $status)));
    @endphp
    <div class="flex flex-col gap-6">
        <x-page-header title="{{ $claim->exists ? $claim->provider.' · '.$claim->external_reference : __('New carrier claim') }}" subtitle="{{ __('Enter statuses, dates and amounts as they appear in the carrier or insurer portal.') }}">
            <x-button variant="ghost" :href="route('carrier-claims.index')">{{ __('All claims') }}</x-button>
        </x-page-header>

        @if ($summary)
            <x-card>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['goods' => __('Goods compensation'), 'postage' => __('Postage compensation')] as $kind => $label)
                        <div>
                            <h3 class="font-semibold">{{ $label }} ({{ $claim->currency }})</h3>
                            <dl class="mt-2 grid grid-cols-2 gap-1 text-sm">
                                <dt>{{ __('Requested') }}</dt><dd class="text-right">{{ $summary[$kind]['requested'] ?? '—' }}</dd>
                                <dt>{{ __('Approved') }}</dt><dd class="text-right">{{ $summary[$kind]['approved'] ?? '—' }}</dd>
                                <dt>{{ __('Received') }}</dt><dd class="text-right">{{ $summary[$kind]['received'] }}</dd>
                                <dt>{{ __('Outstanding') }}</dt><dd class="text-right font-semibold">{{ $summary[$kind]['outstanding'] ?? '—' }}</dd>
                            </dl>
                            @if ($summary[$kind]['over_received'])<x-alert tone="warn" class="mt-2">{{ __('Received exceeds approved; check for a double-counted posting.') }}</x-alert>@endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">{{ __('Customer refunds are not part of this register. Approval does not mean the money has arrived; only recorded reimbursements count as received.') }}</p>
            </x-card>
        @endif

        <x-card>
            <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" method="POST" action="{{ $claim->exists ? route('carrier-claims.update', $claim->id) : route('carrier-claims.store') }}">
                @csrf
                @if ($claim->exists) @method('PUT') @endif
                <input type="hidden" name="operational_issue_id" value="{{ $value('operational_issue_id') }}">
                @foreach ([['provider', 'Provider', 'text', 80], ['account', 'Account', 'text', 120], ['external_reference', 'Claim reference', 'text', 120], ['order_number', 'Order number', 'text', 64], ['tracking_number', 'Tracking number', 'text', 64]] as [$field, $label, $type, $max])
                    <div>
                        <label class="text-sm font-medium" for="{{ $field }}">{{ __($label) }}</label>
                        <input class="{{ $input }}" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $max }}" value="{{ $value($field) }}" @required(in_array($field, ['provider', 'external_reference'], true))>
                        @error($field)<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div>
                    <label class="text-sm font-medium" for="status">{{ __('Status') }}</label>
                    <select class="{{ $input }}" id="status" name="status">@foreach ($statuses as $status)<option value="{{ $status }}" @selected($value('status') === $status)>{{ $statusText($status) }}</option>@endforeach</select>
                    @error('status')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="currency">{{ __('Currency') }}</label>
                    <input class="{{ $input }}" id="currency" name="currency" maxlength="3" required value="{{ $value('currency') }}">
                    @error('currency')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                @foreach ([['goods_requested', 'Goods requested'], ['goods_approved', 'Goods approved'], ['postage_requested', 'Postage requested'], ['postage_approved', 'Postage approved']] as [$field, $label])
                    <div>
                        <label class="text-sm font-medium" for="{{ $field }}">{{ __($label) }}</label>
                        <input class="{{ $input }}" id="{{ $field }}" name="{{ $field }}" inputmode="decimal" value="{{ old($field, $claim->{$field} !== null ? rtrim(rtrim((string) $claim->{$field}, '0'), '.') : '') }}">
                        @error($field)<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                @foreach ([['submitted_on', 'Submitted on'], ['decision_on', 'Decision on'], ['follow_up_on', 'Follow-up reminder'], ['deadline_on', 'Claim deadline']] as [$field, $label])
                    <div>
                        <label class="text-sm font-medium" for="{{ $field }}">{{ __($label) }}</label>
                        <input class="{{ $input }}" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ $date($field) }}">
                        @error($field)<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div>
                    <label class="text-sm font-medium" for="carrier_claim_policy_id">{{ __('Verified policy') }}</label>
                    <select class="{{ $input }}" id="carrier_claim_policy_id" name="carrier_claim_policy_id">
                        <option value="">—</option>
                        @foreach ($policies as $policy)<option value="{{ $policy->id }}" @selected((string) old('carrier_claim_policy_id', $claim->carrier_claim_policy_id) === (string) $policy->id)>{{ $policy->label() }}</option>@endforeach
                    </select>
                    @error('carrier_claim_policy_id')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium" for="deadline_policy">{{ __('Deadline policy (provider, account and service)') }}</label>
                    <input class="{{ $input }}" id="deadline_policy" name="deadline_policy" maxlength="255" value="{{ $value('deadline_policy') }}">
                    @error('deadline_policy')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="text-sm font-medium" for="evidence">{{ __('Evidence links (https, one per line)') }}</label>
                    <textarea class="{{ $input }}" id="evidence" name="evidence" rows="3">{{ old('evidence', implode("\n", $claim->evidence ?? [])) }}</textarea>
                    @error('evidence')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    @error('evidence.*')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" name="dates_verified" value="1" @checked(old('dates_verified', $claim->dates_verified_at !== null))> {{ __('I checked these dates in the carrier or insurer portal') }}</label>
                @error('dates_verified')<p class="text-sm text-red-600 dark:text-red-400 sm:col-span-3">{{ $message }}</p>@enderror
                @error('operational_issue_id')<p class="text-sm text-red-600 dark:text-red-400 sm:col-span-3">{{ $message }}</p>@enderror
                <div class="sm:col-span-2 lg:col-span-3"><x-button type="submit">{{ __('Save claim') }}</x-button></div>
            </form>
        </x-card>

        @if ($claim->exists)
            <x-card>
                <h3 class="text-lg font-semibold">{{ __('Record a reimbursement received') }}</h3>
                <form class="mt-4 grid gap-4 sm:grid-cols-3" method="POST" action="{{ route('carrier-claims.payments.store', $claim->id) }}">
                    @csrf
                    <div>
                        <label class="text-sm font-medium" for="kind">{{ __('Type') }}</label>
                        <select class="{{ $input }}" id="kind" name="kind"><option value="goods">{{ __('Goods') }}</option><option value="postage">{{ __('Postage') }}</option></select>
                    </div>
                    <div><label class="text-sm font-medium" for="amount">{{ __('Amount') }}</label><input class="{{ $input }}" id="amount" name="amount" inputmode="decimal" required>@error('amount')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
                    <div><label class="text-sm font-medium" for="payment_currency">{{ __('Currency') }}</label><input class="{{ $input }}" id="payment_currency" name="currency" maxlength="3" required value="{{ $claim->currency }}">@error('currency')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
                    <div><label class="text-sm font-medium" for="received_on">{{ __('Received on') }}</label><input class="{{ $input }}" id="received_on" name="received_on" type="date" required>@error('received_on')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
                    <div><label class="text-sm font-medium" for="source_note">{{ __('Where you saw it') }}</label><input class="{{ $input }}" id="source_note" name="source_note" maxlength="255" required>@error('source_note')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
                    <div><label class="text-sm font-medium" for="ledger_reference">{{ __('Billing ledger reference (optional)') }}</label><input class="{{ $input }}" id="ledger_reference" name="ledger_reference" maxlength="120" list="ledger-candidates"><datalist id="ledger-candidates">@foreach ($candidates as $candidate)<option value="{{ $candidate['transaction_id'] }}">{{ $candidate['amount'] }} {{ $candidate['currency'] }} · {{ $candidate['posted_at'] }}</option>@endforeach</datalist>@error('ledger_reference')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-3"><x-button type="submit">{{ __('Record reimbursement') }}</x-button></div>
                </form>
                <x-data-table class="mt-4" :headers="['Received on', 'Type', 'Amount', 'Source']">
                    @forelse ($claim->payments->sortBy('received_on') as $payment)
                        <tr><td class="px-4 py-3">{{ \App\Support\UiFormat::date($payment->received_on) }}</td><td class="px-4 py-3">{{ $payment->kind === 'goods' ? __('Goods') : __('Postage') }}</td><td class="px-4 py-3">{{ rtrim(rtrim((string) $payment->amount, '0'), '.') }} {{ $payment->currency }}</td><td class="px-4 py-3">{{ $payment->source_note }}@if ($payment->ledger_reference) <span class="text-xs text-slate-500">({{ $payment->ledger_reference }})</span>@endif</td></tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-slate-500" colspan="4">{{ __('No reimbursements recorded.') }}</td></tr>
                    @endforelse
                </x-data-table>
            </x-card>

            <x-card>
                <h3 class="text-lg font-semibold">{{ __('Documents') }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ __('PDF, JPG, PNG or WebP, up to :mb MB each, at most :max per claim. Files are private to this store.', ['mb' => intdiv(\App\Models\CarrierClaimDocument::MAX_KILOBYTES, 1024), 'max' => \App\Models\CarrierClaimDocument::MAX_PER_CLAIM]) }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($claim->documents as $document)
                        <li class="flex flex-wrap items-center gap-3">
                            <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('carrier-claims.documents.download', [$claim->id, $document->id]) }}">{{ $document->original_name }}</a>
                            <span class="text-slate-500">{{ number_format($document->size / 1024, 0) }} KB · {{ $document->created_at?->format('Y-m-d') }}</span>
                            <form method="POST" action="{{ route('carrier-claims.documents.destroy', [$claim->id, $document->id]) }}">@csrf @method('DELETE')<button class="font-medium text-red-600 hover:underline dark:text-red-400" type="submit">{{ __('Delete') }}</button></form>
                        </li>
                    @empty
                        <li class="text-slate-500">{{ __('No documents yet.') }}</li>
                    @endforelse
                </ul>
                <form class="mt-4 flex flex-wrap items-end gap-3" method="POST" enctype="multipart/form-data" action="{{ route('carrier-claims.documents.store', $claim->id) }}">
                    @csrf
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required aria-label="{{ __('Document') }}">
                    <x-button type="submit">{{ __('Upload document') }}</x-button>
                </form>
                @error('document')<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </x-card>

            <x-card>
                <h3 class="text-lg font-semibold">{{ __('Communication notes') }}</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse (array_reverse($claim->communications ?? []) as $entry)<li><span class="text-slate-500">{{ $entry['on'] }} · {{ $entry['by'] }}</span><br>{{ $entry['text'] }}</li>@empty<li class="text-slate-500">{{ __('No notes yet.') }}</li>@endforelse
                </ul>
                <form class="mt-4 flex flex-col gap-3" method="POST" action="{{ route('carrier-claims.communications.store', $claim->id) }}">
                    @csrf
                    <textarea class="{{ $input }}" name="text" rows="2" maxlength="1000" required aria-label="{{ __('Note') }}"></textarea>
                    @error('text')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    <div><x-button type="submit">{{ __('Add note') }}</x-button></div>
                </form>
            </x-card>
        @endif
    </div>
@endsection
