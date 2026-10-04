@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Risk report" title="{{ __('Same IP, Different Emails') }}" subtitle="Find paid orders where two or more distinct customer emails share the exact client IP. Shared networks can be legitimate, so treat clusters as review signals." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.date-range-form :action="route('reports.same-ip.store')" :start-date="$startDate" :end-date="$endDate" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('scanned ·') }} {{ \App\Support\UiFormat::count(count($result->rows), 'shared IPs') }}</x-slot:heading>

                <x-data-table :headers="['Client IP', 'Emails', 'Orders', 'Details']" :rows="$result->rows" empty="Every client IP in this range was used by only one customer email.">
                    @foreach ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold">{{ $row['ip'] }}</td>
                            <td class="px-4 py-3">
                                {{ $row['email_count'] }}
                                <ul>
                                    @foreach ($row['emails'] as $email)
                                        <li>{{ $email }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">
                                <ul>
                                    @foreach ($row['orders'] as $order)
                                        <li><x-report.shopify-link :id="$order['id']">{{ $order['number'] }}</x-report.shopify-link> · {{ \App\Support\UiFormat::date($order['created_at']) }} · {{ $order['email'] }} · {{ \App\Support\UiFormat::number($order['total'], 2) }} {{ $order['currency'] }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
