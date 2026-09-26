@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations report" title="Order Edit History" subtitle="Orders that were edited after they were placed." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.order-edits.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ count($result->rows) }} edited orders</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: events truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                @forelse ($result->rows as $row)
                    <x-card>
                        <strong>{{ $row['order_number'] }}</strong> · {{ $row['edited_at'] }} · {{ $row['diff_mins'] }} minutes
                        @foreach ($row['edit_summary'] as $message)
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __($message) }}</p>
                        @endforeach
                    </x-card>
                @empty
                    <x-empty-state icon="✓">No edited orders were found in this range.</x-empty-state>
                @endforelse
            </section>
        @endif
    </div>
@endsection
