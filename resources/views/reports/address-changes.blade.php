@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Address Changes" subtitle="Orders whose shipping address was edited after placement." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.address-changes.store') }}">
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
            <div class="flex items-end gap-2">
                <x-button type="submit">{{ __('Run report') }}</x-button>
                <x-button type="submit" variant="ghost" formaction="{{ route('reports.address-changes.export') }}">{{ __('Download CSV') }}</x-button>
            </div>
        </form>

        @error('export')
            <x-alert tone="error">{{ __($message) }}</x-alert>
        @enderror
        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ count($result->rows) }} orders with address changes</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: events truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif
                <x-data-table :headers="['Order', 'Placed', 'Changed', 'Time gap', 'Email', 'Current shipping address', 'Total', 'Status']">
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($row['shopify_id'] !== '')
                                    <a class="text-indigo-600 dark:text-indigo-400" href="https://admin.shopify.com/store/{{ rawurlencode($activeStore->shopify_store) }}/orders/{{ $row['shopify_id'] }}">{{ $row['order_number'] }}</a>
                                @else
                                    {{ $row['order_number'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['changed_at'] }}</td>
                            <td class="px-4 py-3">{{ $row['gap_mins'] }} minutes</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3"><strong>{{ $row['addr_name'] }}</strong><br>{{ $row['addr_line'] }}</td>
                            <td class="px-4 py-3">{{ number_format($row['total'], 2) }}</td>
                            <td class="px-4 py-3">{{ $row['financial'] }} {{ $row['fulfillment'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="8">{{ __('No address changes found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
