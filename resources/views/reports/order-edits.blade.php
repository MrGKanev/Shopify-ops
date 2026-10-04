@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Operations report" title="{{ __('Order Edit History') }}" subtitle="Orders that were edited after they were placed." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.order-edits.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results are incomplete: events truncated after :pages pages." :pages="$result->pages">
                <x-slot:heading>{{ \App\Support\UiFormat::count(count($result->rows), 'edited orders') }}</x-slot:heading>

                @forelse ($result->rows as $row)
                    <x-card>
                        <strong>{{ $row['order_number'] }}</strong> · {{ \App\Support\UiFormat::date($row['edited_at'], true) }} · {{ $row['diff_mins'] }} {{ __('minutes') }}
                        @foreach ($row['edit_summary'] as $message)
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __($message) }}</p>
                        @endforeach
                    </x-card>
                @empty
                    <x-empty-state icon="✓">{{ __('No edited orders were found in this range.') }}</x-empty-state>
                @endforelse
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
