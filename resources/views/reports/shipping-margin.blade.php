@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Shipping Margin Erosion" subtitle="ShipStation label cost compared with shipping charged in Shopify." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.shipping-margin.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate, 'date'], 'end_date' => ['To', $endDate, 'date'], 'threshold' => ['Loss threshold', $threshold, 'number']] as $field => [$label, $value, $type])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" min="{{ $type === 'number' ? 1 : '' }}" step="{{ $type === 'number' ? '0.01' : '' }}" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end"><x-button type="submit">{{ __('Run report') }}</x-button></div>
        </form>

        @error('export')
            <x-alert tone="error">{{ __($message) }}</x-alert>
        @enderror
        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify or ShipStation credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and ShipStation, then try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-bold">{{ $result->scanned }} shipments scanned · {{ count($result->rows) }} losses over ${{ number_format($result->threshold, 2) }}</h2>
                    <form method="POST" action="{{ route('reports.shipping-margin.export') }}">
                        @csrf
                        <input type="hidden" name="start_date" value="{{ $result->startDate }}">
                        <input type="hidden" name="end_date" value="{{ $result->endDate }}">
                        <input type="hidden" name="threshold" value="{{ $result->threshold }}">
                        <x-button type="submit" variant="ghost">{{ __('Download CSV') }}</x-button>
                    </form>
                </div>
                @if ($result->shopifyTruncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: Shopify orders were truncated after :pages pages.', ['pages' => $result->shopifyPages]) }}</x-alert>
                @endif

                @if ($result->byCarrier)
                    <x-data-table :headers="['Carrier', 'Orders', 'Total loss', 'Average loss']">
                        @foreach ($result->byCarrier as $row)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $row['carrier'] }}</td>
                                <td class="px-4 py-3">{{ $row['count'] }}</td>
                                <td class="px-4 py-3">${{ number_format($row['total_loss'], 2) }}</td>
                                <td class="px-4 py-3">${{ number_format($row['avg_loss'], 2) }}</td>
                            </tr>
                        @endforeach
                    </x-data-table>
                @endif

                <x-data-table :headers="['Order', 'Ship date', 'Carrier / service', 'Ship cost', 'Charged', 'Loss', 'Email', 'Total']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $row['order_number'] }}</td>
                            <td class="px-4 py-3">{{ $row['ship_date'] }}</td>
                            <td class="px-4 py-3">{{ $row['carrier'] }}{{ $row['service'] ? ' / '.$row['service'] : '' }}</td>
                            <td class="px-4 py-3">${{ number_format($row['ship_cost'], 2) }}</td>
                            <td class="px-4 py-3">${{ number_format($row['shipping_charged'], 2) }}</td>
                            <td class="px-4 py-3 font-semibold text-red-600 dark:text-red-400">${{ number_format($row['loss'], 2) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] ?: '—' }}</td>
                            <td class="px-4 py-3">${{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="8">{{ __('No margin-eroding shipments found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
