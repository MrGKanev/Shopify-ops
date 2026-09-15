@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations report" title="Note Flags" subtitle="Find paid, unfulfilled orders whose notes need attention." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.note-flags.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
            <div>
                <label class="text-sm font-medium" for="keywords">Keywords</label>
                <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="keywords" name="keywords" value="{{ old('keywords', $keywords) }}">
                @error('keywords')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-end">
                <x-button type="submit">Run report</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">Shopify credentials are incomplete for the active store.</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">The report could not be completed. Check Shopify and try again.</x-alert>
        @endif

        @if ($result)
            <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} flagged notes</h2>
            @if ($result->truncated)
                <x-alert tone="warn">Results are incomplete: orders truncated after {{ $result->pages }} pages.</x-alert>
            @endif

            <x-data-table :headers="['Order', 'Placed', 'Matched', 'Note', 'Email']">
                @forelse ($result->rows as $row)
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            @if ($row['shopify_id'])
                                <a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $row['shopify_id'] }}">{{ $row['order_number'] }}</a>
                            @else
                                {{ $row['order_number'] }}
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                        <td class="px-4 py-3">{{ implode(', ', $row['matched']) }}</td>
                        <td class="px-4 py-3">{{ $row['note'] }}</td>
                        <td class="px-4 py-3">{{ $row['email'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="5">No order notes matched the keywords.</td>
                    </tr>
                @endforelse
            </x-data-table>
        @endif
    </div>
@endsection
