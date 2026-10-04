@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Audit report" title="Tag audit" subtitle="Inventory every order tag, its usage frequency, and the most recent order carrying it." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.tag-audit.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages pages. Narrow the date range for a complete inventory." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} unique tags</x-slot:heading>
                <x-slot:summary>{{ $result->params['startDate'] }} → {{ $result->params['endDate'] }}</x-slot:summary>

                <x-data-table :headers="['Tag', 'Orders', 'Last seen', 'Last order', 'Search']" :rows="$result->rows" empty="No orders in this date range have tags.">
                    @foreach ($result->rows as $row)
                        <tr class="{{ $row['orphan'] ? 'text-slate-400' : '' }}">
                            <td class="px-4 py-3"><code>{{ $row['tag'] }}</code> @if ($row['orphan'])<x-badge tone="warn" class="ml-2">{{ __('orphan') }}</x-badge>@endif</td>
                            <td class="px-4 py-3">{{ $row['count'] }}</td>
                            <td class="px-4 py-3">{{ $row['last_date'] ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row['last_order'] ?: '—' }}</td>
                            <td class="px-4 py-3"><a class="font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('orders.tag-search', ['tag' => $row['tag']]) }}">{{ __('Search') }}</a></td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
