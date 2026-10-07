@extends('layouts.app')

@section('content')
    <x-report.layout eyebrow="Catalogue report" title="{{ __('Catalog quality') }}" subtitle="Find active products with publishing, search visibility, or collection gaps." :configuration-error="$configurationError" :report-failed="$reportFailed">
        <x-slot:form>
            <x-card>
                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>{{ __('Checks publication to the Online Store channel.') }}</li>
                    <li>{{ __('Checks custom SEO title and description.') }}</li>
                    <li>{{ __('Checks whether the product belongs to at least one collection.') }}</li>
                </ul>
            </x-card>

            <x-report.params-form :action="route('reports.catalog-quality.store')" submit-label="Scan active products">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="include_customs" value="1" @checked(old('include_customs', request()->boolean('include_customs')))>
                    {{ __('Include customs product defaults (25 products per part)') }}
                </label>
            </x-report.params-form>
        </x-slot:form>

        @if ($result && isset($result->meta['customs']))
            <x-report.customs-readiness :result="$result->meta['customs']" />
            @if ($result->meta['customs']['next_after'] !== null)
                <x-button :href="route('reports.catalog-quality.result', ['include_customs' => 1, 'after' => $result->meta['customs']['next_after']])">{{ __('Next part') }}</x-button>
            @endif
        @endif

        @if ($result)
            <x-report.results :truncated="$result->truncated" :truncated-message="isset($result->meta['customs']) ? 'This customs part is incomplete. Continue with Next part and review variant coverage.' : 'Results were truncated after :pages product pages.'" :pages="$result->pages">
                <x-slot:heading>{{ $result->scanned }} {{ __('active products ·') }} {{ count($result->rows) }} {{ __('with quality issues') }}</x-slot:heading>

                <x-data-table :headers="['Product', 'Vendor / type', 'Issues']" :rows="$result->rows" empty="All scanned active products are published, have SEO fields, and belong to a collection.">
                    @foreach ($result->rows as $row)
                        <tr>
                            <td class="px-4 py-3"><x-report.shopify-link resource="products" :id="$row['id']" class="font-semibold">{{ $row['title'] ?: __('Untitled product') }}</x-report.shopify-link></td>
                            <td class="px-4 py-3">{{ $row['vendor'] ?: '—' }}@if ($row['type']) · {{ $row['type'] }}@endif</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    @foreach ($row['issues'] as $issue)
                                        <x-badge tone="warn" class="w-fit">{{ $issue }}</x-badge>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-report.results>
        @endif
    </x-report.layout>
@endsection
