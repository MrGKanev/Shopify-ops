@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Ignored Orders" :subtitle="$orders->count().' orders excluded from audits for this store.'" />

        <x-card>
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] sm:items-end" method="POST" action="{{ route('ignored-orders.store') }}">
                @csrf
                <div>
                    <label class="text-sm font-medium" for="ignored-order-number">{{ __('Order number') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="ignored-order-number" name="order_number" value="{{ old('order_number') }}" @error('order_number') aria-invalid="true" aria-describedby="ignored-order-number-error" @enderror>
                    @error('order_number')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="ignored-order-number-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="ignored-reason">{{ __('Reason (optional)') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="ignored-reason" name="reason" value="{{ old('reason') }}" @error('reason') aria-invalid="true" aria-describedby="ignored-reason-error" @enderror>
                    @error('reason')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="ignored-reason-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <div><x-button type="submit">Ignore order</x-button></div>
            </form>
        </x-card>

        <x-card>
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] sm:items-end" method="POST" enctype="multipart/form-data" action="{{ route('ignored-orders.import') }}">
                @csrf
                <div>
                    <label class="text-sm font-medium" for="ignored-file">{{ __('CSV file') }}</label>
                    <input class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" id="ignored-file" type="file" name="file" accept=".csv,text/csv" @error('file') aria-invalid="true" aria-describedby="ignored-file-error" @enderror>
                    @error('file')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="ignored-file-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="import-reason">{{ __('Import reason (optional)') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="import-reason" name="reason" @error('reason') aria-invalid="true" aria-describedby="import-reason-error" @enderror>
                    @error('reason')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="import-reason-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <div><x-button type="submit">Import CSV</x-button></div>
            </form>
        </x-card>

        @if ($orders->isNotEmpty())
            <form id="bulk-unignore" method="POST" action="{{ route('ignored-orders.bulk-destroy') }}">
                @csrf
                @method('DELETE')
                <x-button type="submit" variant="danger">Unignore selected</x-button>
            </form>
        @endif

        <x-data-table :headers="['Select', 'Order', 'Ignored on', 'Reason', 'Recurrence', '']">
            @forelse ($orders as $order)
                @php($count = $recurrenceCounts[$order->order_number] ?? 0)
                <tr>
                    <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="{{ $order->id }}" form="bulk-unignore" aria-label="{{ __('Select order :number', ['number' => '#'.$order->order_number]) }}"></td>
                    <td class="px-4 py-3 font-medium">#{{ $order->order_number }}</td>
                    <td class="px-4 py-3">{{ $order->ignored_at->toDateString() }}</td>
                    <td class="px-4 py-3">{{ $order->reason ?: '-' }}</td>
                    <td class="px-4 py-3">
                        @if ($count >= 3)
                            <x-badge tone="danger">Hot · {{ $count }}</x-badge>
                        @elseif ($count >= 2)
                            <x-badge tone="warn">Recurring · {{ $count }}</x-badge>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('ignored-orders.destroy', $order) }}">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" size="sm" variant="danger">Unignore</x-button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="6">{{ __('Nothing ignored yet.') }}</td></tr>
            @endforelse
        </x-data-table>
    </div>
@endsection
