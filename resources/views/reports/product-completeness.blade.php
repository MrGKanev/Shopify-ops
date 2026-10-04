@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Catalogue report" title="Product completeness" subtitle="Find active products with no usable image, meaningful description, variants, or SKU." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-report.params-form :action="route('reports.product-completeness.store')" submit-label="Scan active products" />
        </x-slot:form>

        @if ($result)
            <x-report.results :truncated="$result->truncated" truncated-message="Results were truncated after :pages product pages. The report is not a complete store inventory." :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} scanned · {{ count($result->rows) }} with issues</x-slot:heading>
                <x-slot:summary>{{ $result->meta['critical'] }} critical · {{ $result->meta['warnings'] }} warnings</x-slot:summary>

                <x-data-table :headers="['Product', 'Vendor / type', 'Images', 'Variants', 'Issues', 'Severity']" :rows="$result->rows" empty="All scanned active products are complete.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3"><x-report.shopify-link resource="products" :id="$row['id']" class="font-semibold">{{ $row['title'] ?: 'Untitled product' }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ $row['vendor'] ?: '—' }}@if ($row['type']) · {{ $row['type'] }}@endif</td>
                            <td class="px-4 py-3">{{ $row['images'] }}</td>
                            <td class="px-4 py-3">{{ $row['variants'] }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    @foreach ($row['issues'] as $issue)
                                        <span>{{ $issue['message'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3"><x-badge :tone="$row['severity'] === 'critical' ? 'danger' : 'warn'">{{ $row['severity'] }}</x-badge></td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
