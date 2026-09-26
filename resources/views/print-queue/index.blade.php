@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Print Queue" subtitle="Queue ShipStation orders for packing-slip printing." />

        <x-card>
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] sm:items-end" method="POST">
                @csrf
                <div>
                    <label class="text-sm font-medium" for="order_number">{{ __('Order number') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="order_number" name="order_number" required value="{{ old('order_number') }}" @error('order_number') aria-invalid="true" aria-describedby="order-number-error" @enderror>
                    @error('order_number')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="order-number-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-medium" for="note">Note</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="note" maxlength="255" name="note" value="{{ old('note') }}" @error('note') aria-invalid="true" aria-describedby="note-error" @enderror>
                    @error('note')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="note-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <div><x-button type="submit">Add</x-button></div>
            </form>
        </x-card>

        <section class="flex flex-col gap-4" aria-labelledby="queued-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-2xl font-bold" id="queued-heading">{{ $items->count() }} queued</h2>
                @if ($items->isNotEmpty())
                    <form method="POST" action="{{ route('print-queue.clear') }}">
                        @csrf
                        @method('DELETE')
                        <x-button type="submit" variant="danger">Clear all</x-button>
                    </form>
                @endif
            </div>

            <x-data-table :headers="['Order', 'Note', 'Queued', 'Actions']">
                @forelse ($items as $item)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $item->order_number }}</td>
                        <td class="px-4 py-3">{{ $item->note ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $item->created_at->toDateTimeString() }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('orders.packing-slip', ['order' => $item->order_number]) }}" target="_blank" rel="noopener noreferrer">{{ __('Print') }}</a>
                                <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => $item->order_number]) }}">Spot-check</a>
                                <form method="POST" action="{{ route('print-queue.destroy', $item) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="font-medium text-red-600 hover:underline dark:text-red-400" type="submit">{{ __('Remove') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">{{ __('Queue is empty.') }}</td></tr>
                @endforelse
            </x-data-table>
        </section>
    </div>
@endsection
