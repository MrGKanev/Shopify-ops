@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Audit report" title="Tag audit" subtitle="Inventory every order tag, its usage frequency, and the most recent order carrying it." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.tag-audit.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end"><x-button type="submit">{{ __('Run report') }}</x-button></div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <div>
                    <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->tags) }} unique tags</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $result->startDate }} → {{ $result->endDate }}</p>
                </div>
                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results were truncated after :pages pages. Narrow the date range for a complete inventory.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Tag', 'Orders', 'Last seen', 'Last order', 'Search']">
                    @forelse ($result->tags as $row)
                        <tr class="{{ $row['orphan'] ? 'text-slate-400' : '' }}">
                            <td class="px-4 py-3"><code>{{ $row['tag'] }}</code> @if ($row['orphan'])<x-badge tone="warn" class="ml-2">{{ __('orphan') }}</x-badge>@endif</td>
                            <td class="px-4 py-3">{{ $row['count'] }}</td>
                            <td class="px-4 py-3">{{ $row['last_date'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row['last_order'] ?: '—' }}</td>
                            <td class="px-4 py-3"><a class="font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('orders.tag-search', ['tag' => $row['tag']]) }}">{{ __('Search') }}</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="5">{{ __('No orders in this date range have tags.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
