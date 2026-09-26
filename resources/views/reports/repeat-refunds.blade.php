@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Repeat Refunds" subtitle="Customers with multiple refunded orders." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.repeat-refunds.store') }}">
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
            <div>
                <label class="text-sm font-medium" for="minimum">{{ __('Minimum refunds') }}</label>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="minimum" name="minimum" min="2" max="100" type="number" value="{{ old('minimum', $minimum) }}">
                @error('minimum')
                    <p class="text-sm text-red-600">{{ __($message) }}</p>
                @enderror
            </div>
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
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} repeat customers</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Email', 'Refund count', 'Total refunded', 'Orders']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['refund_count'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total_refunded'], 2) }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['orders'] as $order)
                                    <span>{{ $order['order_number'] }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="4">{{ __('No repeat refund customers found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
