@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="Tag Policy Audit" subtitle="Check paid orders against required and forbidden tag combinations." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            @unless ($configured)
                <x-alert tone="warn">{{ __('No tag policy is configured. Add required or forbidden rules in') }} <code>config/tag-policy.php</code> {{ __('to enable this audit.') }}</x-alert>
            @endunless

            <x-report.date-range-form :action="route('reports.tag-policy.store')" :start-date="$startDate" :end-date="$endDate" :submit-disabled="! $configured" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} policy violations</x-slot:heading>

                <x-data-table :headers="['Order', 'Placed', 'Violations', 'Tags', 'Email', 'Status']" :rows="$result->rows" empty="No scanned orders violated the configured tag policy.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold"><x-report.shopify-link :id="$row['shopify_id']">{{ $row['order_number'] }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ $row['created_at'] }}</td>
                            <td class="px-4 py-3">
                                @foreach ($row['violations'] as $violation)
                                    <div><span class="font-medium">{{ $violation['name'] }}</span><br><span class="text-slate-500 dark:text-slate-400">{{ $violation['detail'] }}</span></div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">{{ implode(', ', $row['tags']) }}</td>
                            <td class="px-4 py-3">{{ $row['email'] }}</td>
                            <td class="px-4 py-3">{{ $row['financial'] ?: '—' }}@if ($row['fulfillment']) · {{ str_replace('_', ' ', $row['fulfillment']) }}@endif</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
