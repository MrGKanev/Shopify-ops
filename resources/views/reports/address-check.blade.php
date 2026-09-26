@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Fulfillment report" title="Address Scanner" subtitle="Find incomplete, invalid, short, or carrier-incompatible shipping addresses on paid orders." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.address-check.store') }}">
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
            <div class="flex flex-col justify-end gap-2">
                <label><input name="unfulfilled_only" type="checkbox" value="1" @checked(old('unfulfilled_only', $unfulfilledOnly))> {{ __('Unfulfilled only') }}</label>
                <label><input name="po_box_only" type="checkbox" value="1" @checked(old('po_box_only', $poBoxOnly))> {{ __('PO Box issues only') }}</label>
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
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ $result->critical }} critical · {{ $result->warnings }} warnings</h2>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif
                @if ($result->rows !== [])
                    @include('partials.bulk-ignore-form')
                @endif

                <x-data-table :headers="['Select', 'Severity', 'Order', 'Email', 'Address', 'Issues']">
                    @forelse ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3"><input type="checkbox" name="order_numbers[]" value="{{ $row['number'] }}" form="bulk-ignore" aria-label="{{ __('Select order :number', ['number' => $row['number']]) }}"></td>
                            <td class="px-4 py-3 font-semibold">{{ ucfirst($row['severity']) }}</td>
                            <td class="px-4 py-3">
                                @if ($row['id'])
                                    <a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['id'] }}" target="_blank" rel="noopener noreferrer">{{ $row['number'] }}</a>
                                @else
                                    {{ $row['number'] }}
                                @endif
                                <br>{{ $row['created_at'] }}
                            </td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['address']['address1'] ?? 'Missing' }}<br>{{ $row['address']['city'] ?? '' }} {{ $row['address']['zip'] ?? '' }} {{ $row['address']['country_code'] ?? '' }}</td>
                            <td class="px-4 py-3">
                                <ul class="list-disc pl-5">
                                    @foreach ($row['issues'] as $issue)
                                        <li>{{ $issue['message'] }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="6">{{ __('No address issues were found.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
