@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Global Search" subtitle="Search order numbers across saved reports, push log and ignored orders." />

        <x-card>
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" method="GET">
                <div class="min-w-0 flex-1">
                    <label class="text-sm font-medium" for="q">{{ __('Order number') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="q" name="q" value="{{ old('q', $query) }}" placeholder="#1001" @error('q') aria-invalid="true" aria-describedby="q-error" @enderror>
                    @error('q')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400" id="q-error">{{ __($message) }}</p>
                    @enderror
                </div>
                <x-button type="submit">{{ __('Search') }}</x-button>
            </form>
        </x-card>

        @if ($results !== null)
            @php($total = count($results['reports']) + $results['pushes']->count() + $results['ignored']->count())
            <h2 class="text-2xl font-bold">{{ $total }} matches for “{{ $query }}”</h2>

            @if ($total === 0)
                <x-empty-state title="Nothing found locally">
                    <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('orders.spot-check', ['prefill' => $query]) }}">{{ __('Run a live Spot-check') }}</a>.
                </x-empty-state>
            @else
                @if ($results['reports'])
                    <section class="flex flex-col gap-3" aria-labelledby="saved-reports-heading">
                        <h3 class="text-xl font-bold" id="saved-reports-heading">{{ __('Saved reports') }}</h3>
                        @foreach ($results['reports'] as $row)
                            <x-card padding="p-3">
                                <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('saved-reports.show', $row['id']) }}">{{ $row['order_number'] }}</a> · {{ $row['report_date'] }}
                            </x-card>
                        @endforeach
                    </section>
                @endif

                @if ($results['pushes']->isNotEmpty())
                    <section class="flex flex-col gap-3" aria-labelledby="push-log-heading">
                        <h3 class="text-xl font-bold" id="push-log-heading">{{ __('Push log') }}</h3>
                        @foreach ($results['pushes'] as $row)
                            <x-card padding="p-3">{{ $row->order_number }} · {{ $row->pushed_at->toDateTimeString() }}</x-card>
                        @endforeach
                    </section>
                @endif

                @if ($results['ignored']->isNotEmpty())
                    <section class="flex flex-col gap-3" aria-labelledby="ignored-orders-heading">
                        <h3 class="text-xl font-bold" id="ignored-orders-heading">Ignored orders</h3>
                        @foreach ($results['ignored'] as $row)
                            <x-card padding="p-3">{{ $row->order_number }} · {{ $row->reason ?: 'No reason' }}</x-card>
                        @endforeach
                    </section>
                @endif
            @endif
        @endif
    </div>
@endsection
