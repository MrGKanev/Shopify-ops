@extends('layouts.app')
@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" :title="__('Shipping Billing Audit')" subtitle="Inspect imported charges and credits, track disputes and reconcile explicitly expected label refunds." />
        <x-alert tone="warn">{{ __('Imported postings are an observed cost basis, not proof of a final or complete invoice. Amounts in different currencies are never combined. A dispute decision does not count as received money.') }}</x-alert>
        <x-alert tone="warn">{{ __('Imports belong to the active store and current ShipStation credential set. After changing accounts or credentials, old imports remain stored but are not matched automatically.') }}</x-alert>
        <p>{{ __('Run reports again after importing. Saved results retain their original snapshot.') }}</p>
        @if ($errors->any())<x-alert tone="error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-alert>@endif
        <x-card>
            <h2 class="mb-3 text-xl font-bold">{{ __('Import prepared billing CSV') }}</h2>
            <p class="mb-3">{{ __('Map your statement to the template; raw carrier exports may use different columns. Use a stable source account reference across overlapping statements. All rows must use the active ShipStation store number and exact shipment/order IDs. One row represents one posted transaction.') }}</p>
            <p class="my-3">{{ __('ShipStation store ID') }}: {{ $shipStationStoreNumber }}</p>
            <x-button variant="ghost" :href="route('shipping-billing.template')">{{ __('Download billing template') }}</x-button>
            <p class="my-3 text-sm">{{ __('Allowed transaction types') }}: <code>label_charge, insurance_charge, adjustment_debit, adjustment_credit, label_refund, label_refund_reversal</code></p>
            <form class="grid gap-4 sm:grid-cols-2" method="POST" enctype="multipart/form-data" action="{{ route('shipping-billing.preview') }}">
                @csrf
                <label class="flex flex-col gap-2">{{ __('Source account reference') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="source_account" maxlength="80" required value="{{ old('source_account') }}" placeholder="carrier-account-123"></label>
                <label class="flex flex-col gap-2">{{ __('Statement reference') }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="statement_reference" maxlength="160" required value="{{ old('statement_reference') }}"></label>
                <label class="flex flex-col gap-2">CSV<input type="file" name="file" accept=".csv,.txt" required></label>
                <div class="flex items-end"><x-button type="submit">{{ __('Preview import') }}</x-button></div>
            </form>
        </x-card>
        <x-card>
            <form class="grid gap-4 sm:grid-cols-4" method="GET">
                @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])<label class="flex flex-col gap-2">{{ __($label) }}<input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" type="date" name="{{ $field }}" value="{{ $value }}"></label>@endforeach
                <label class="flex flex-col gap-2">{{ __('Group by') }}<select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" name="group_by">@foreach (['carrier' => 'Carrier', 'service' => 'Service', 'package' => 'Package', 'skus' => 'SKU set'] as $key => $label)<option value="{{ $key }}" @selected($dimension === $key)>{{ __($label) }}</option>@endforeach</select></label>
                <div class="flex items-end"><x-button type="submit">{{ __('Apply') }}</x-button></div>
            </form>
        </x-card>
        <div><x-button variant="ghost" :href="route('shipping-billing.export', ['start_date' => $startDate, 'end_date' => $endDate])">{{ __('Download transactions CSV') }}</x-button></div>
        <p>{{ __('Select posting dates. Totals include all imported postings through the end date for labels appearing in the selection. SKU sets are grouped together; costs are not allocated or counted once per individual SKU.') }}</p>
        @if ($truncated)<x-alert tone="warn">{{ __('The selection is incomplete. Narrow the date range; no complete billing reconciliation is implied.') }}</x-alert>@endif
        <x-data-table :rows="$grouped" empty="No imported billing in this period." :columns="['Group' => fn (array $row) => $row['label'] === '' ? __('Unknown') : $row['label'], 'Currency' => 'currency', 'Labels' => 'labels', 'Observed net cost' => 'net', 'Adjustments' => 'adjustments', 'Credits' => 'credits', 'Received label refunds' => 'refunds']" />
        <x-data-table :rows="$groups" empty="No label billing groups." :columns="['Order' => 'order_number', 'Shipment ID' => 'shipment_id', 'Source account' => 'source_account', 'Currency' => 'currency', 'Label and insurance charges' => 'charges', 'Adjustments' => 'adjustments', 'Credits' => 'credits', 'Observed net cost' => 'observed_net', 'Expected refund' => fn (array $row) => $row['expected_refund'] ?? __('Unknown'), 'Received label refunds' => 'refunds', 'Outstanding refund' => fn (array $row) => $row['outstanding_refund'] ?? __('Unknown'), 'Refund status' => fn (array $row) => __($row['refund_status'])]" />
        <h2 class="text-xl font-bold">{{ __('Imported transactions and review') }}</h2>
        <x-data-table :rows="$entries" :headers="['Transaction', 'Posted', 'Order / label', 'Type', 'Amount', 'Reason', 'Review']" empty="No imported transactions.">
            @foreach ($entries as $entry)
                <tr class="align-top">
                    <td class="px-4 py-3">{{ $entry->transaction_id }}<div>{{ $entry->source_account }}</div><div>{{ $entry->statement_reference }}</div>@if ($entry->excluded)<x-badge tone="warn">{{ __('Excluded from totals') }}</x-badge>@endif</td>
                    <td class="px-4 py-3">{{ $entry->posted_at->toDateString() }}</td>
                    <td class="px-4 py-3">{{ $entry->order_number }}<div>{{ $entry->shipment_id }}</div><div>{{ $entry->tracking_number }}</div></td>
                    <td class="px-4 py-3">{{ __($entry->type) }}</td>
                    <td class="px-4 py-3">{{ $entry->amount }} {{ $entry->currency }}</td>
                    <td class="px-4 py-3">{{ $entry->reason }}</td>
                    <td class="px-4 py-3">
                        <x-badge>{{ __($entry->dispute_status) }}</x-badge>
                        <details><summary class="cursor-pointer font-semibold">{{ __('Review transaction') }}</summary>
                            <form class="mt-3 flex flex-col gap-3" method="POST" action="{{ route('shipping-billing.update', $entry) }}">
                                @csrf @method('PUT')
                                <label>{{ __('Dispute status') }}<select class="block w-full rounded border bg-white p-2 dark:bg-slate-950" name="dispute_status">@foreach (['none', 'review', 'submitted', 'accepted', 'rejected'] as $status)<option value="{{ $status }}" @selected($entry->dispute_status === $status)>{{ __($status) }}</option>@endforeach</select></label>
                                <label>{{ __('Review note') }}<textarea class="block w-full rounded border bg-white p-2 dark:bg-slate-950" name="review_note" maxlength="2000">{{ $entry->review_note }}</textarea></label>
                                <label><input type="checkbox" name="excluded" value="1" @checked($entry->excluded)> {{ __('Exclude from totals (note required)') }}</label>
                                @if ($entry->type === 'label_charge')
                                    <label>{{ __('Expected label refund') }} ({{ $entry->currency }})<input class="block w-full rounded border bg-white p-2 dark:bg-slate-950" name="expected_refund" inputmode="decimal" value="{{ $entry->expected_refund }}"></label>
                                    <label>{{ __('Refund due date') }}<input class="block w-full rounded border bg-white p-2 dark:bg-slate-950" type="date" name="refund_due_at" value="{{ $entry->refund_due_at?->toDateString() }}"></label>
                                @endif
                                <x-button type="submit" size="sm">{{ __('Save review') }}</x-button>
                            </form>
                        </details>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </div>
@endsection
