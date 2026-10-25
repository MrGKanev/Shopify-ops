@extends('layouts.app')
@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" :title="__('Preview billing import')" :subtitle="$sourceAccount.' · '.$statement" />
        <x-alert tone="warn">{{ __('Review before importing. Amounts are nonnegative; transaction type determines debit or credit. Existing identical transactions are skipped; conflicting IDs reject the entire import. No carrier or order is changed.') }}</x-alert>
        <x-card><p>{{ __('Transactions in file') }}: {{ count($rows) }}</p><p>{{ __('First 20 rows are shown. Confirm imports the whole validated file. Preview expires after 10 minutes.') }}</p></x-card>
        <x-data-table :rows="array_slice($rows, 0, 20)" :columns="['Transaction' => 'transaction_id', 'Shipment ID' => 'shipment_id', 'Order' => 'order_number', 'Type' => 'type', 'Amount' => 'amount', 'Currency' => 'currency', 'Posted' => 'posted_at', 'Reason' => 'reason']" />
        <form method="POST" action="{{ route('shipping-billing.store') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><x-button type="submit">{{ __('Confirm billing import') }}</x-button> <x-button variant="ghost" :href="route('shipping-billing.index')">{{ __('Cancel') }}</x-button></form>
    </div>
@endsection
