@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment audit" title="Carrier Performance" subtitle="Average delivery time and late-delivery rate grouped by ShipStation carrier." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.carrier-performance.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">Run audit</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('ShipStation credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The audit could not be completed. Check ShipStation and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} shipments · {{ count($result->rows) }} carriers</h2>
                <x-data-table :headers="['Carrier', 'Shipments', 'With delivery date', 'Avg delivery days', 'Late deliveries', 'Late %']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['carrier'] }}</td>
                            <td class="px-4 py-3">{{ $row['count'] }}</td>
                            <td class="px-4 py-3">{{ $row['with_delivery'] }}</td>
                            <td class="px-4 py-3">{{ $row['avg_days'] === null ? '—' : $row['avg_days'].' days' }}</td>
                            <td class="px-4 py-3">{{ $row['late_count'] }}</td>
                            <td class="px-4 py-3">{{ $row['late_pct'] === null ? '—' : $row['late_pct'].'%' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">{{ __('No shipments found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
