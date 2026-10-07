@props(['result'])
<x-card>
    <h2 class="text-xl font-semibold">{{ __('Customs readiness') }}</h2>
    <p class="mt-2 text-sm">{{ __('Read-only review. Product defaults are candidates, not proof of the declaration used by a carrier. HS syntax does not validate tariff classification, restricted goods or destination-specific requirements.') }}</p>
    <p class="mt-2 text-sm">{{ __('Origin country means manufacturing origin, not the ship-from warehouse. SS product defaults are shared at account level. Their declared-value currency is unconfirmed; no currency conversion or price comparison is assumed.') }}</p>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Checked at') }} {{ \App\Support\UiFormat::date($result['checked_at'], true) }}</p>
    @if ($result['mode'] === 'order')
        <p class="mt-2">{{ $result['from'] ?: __('Unknown') }} → {{ $result['to'] ?: __('Unknown') }} · {{ $result['carrier'] ?: __('Carrier unconfirmed') }} / {{ $result['service'] ?: __('Service unconfirmed') }}</p>
        <p class="mt-2 text-sm">{{ __('Different countries do not automatically require customs. Check the actual route, territories and carrier/service. V1 order customs rows are a draft, not a carrier-accepted or purchased label declaration.') }}</p>
    @endif
    @if ($result['partial'])
        <x-alert tone="warn" class="mt-3">{{ __('Customs source coverage is incomplete. Missing source data does not prove a customs error.') }}</x-alert>
    @endif
    @foreach ($result['findings'] as $message)<p class="mt-2 text-sm">{{ __($message) }}</p>@endforeach
</x-card>
<x-data-table :headers="['Variant / SKU', 'Source', 'HS / origin', 'Description / value', 'Item weight', 'Review']" :rows="$result['rows']" empty="No physical variants in the available customs data.">
    @foreach ($result['rows'] as $row)
        @foreach ($row['sources'] as $sourceName => $source)
            <tr class="align-top">
                <td class="px-4 py-3">{{ $row['title'] }}<div class="text-xs">{{ $row['sku'] ?: __('Missing SKU') }}</div></td>
                <td class="px-4 py-3">{{ __($sourceName) }}</td>
                <td class="px-4 py-3">{{ $source['hs'] ?: '—' }} / {{ $source['origin'] ?: '—' }}</td>
                <td class="px-4 py-3">{{ $source['description'] ?: '—' }}<div class="text-xs">{{ $source['value'] === null ? __('Unknown') : \App\Support\UiFormat::number($source['value'], 2) }} {{ $source['currency'] ?: __('Currency unconfirmed') }}</div></td>
                <td class="px-4 py-3">{{ $source['grams'] === null ? __('Unknown') : \App\Support\UiFormat::number($source['grams'], 2).' g' }}</td>
                <td class="px-4 py-3">
                    @if ($loop->first)
                        <x-badge :tone="$row['findings'] === [] ? 'info' : 'warn'">{{ $row['findings'] === [] ? __('Default fields available') : __('Review') }}</x-badge>
                        @foreach ($row['findings'] as $finding)<div class="mt-2 text-xs">{{ __($finding) }}</div>@endforeach
                    @endif
                </td>
            </tr>
        @endforeach
    @endforeach
</x-data-table>
@if ($result['declarations'] !== [])
    <x-card>
        <h3 class="text-lg font-semibold">{{ __('Prepared ShipStation customs rows') }}</h3>
        <p class="mt-2 text-sm">{{ __('V1 reports customs value in USD. Product price is not automatically the correct declared value. Rows cannot generally be linked by SKU, and per-row customs weight is unavailable in V1.') }}</p>
    </x-card>
    <x-data-table :headers="['Row', 'Description', 'HS / origin', 'Quantity', 'Declared value', 'Review']" :rows="$result['declarations']">
        @foreach ($result['declarations'] as $row)
            <tr class="align-top">
                <td class="px-4 py-3">{{ $row['id'] }}</td>
                <td class="px-4 py-3">{{ $row['description'] ?: '—' }}</td>
                <td class="px-4 py-3">{{ $row['hs'] ?: '—' }} / {{ $row['origin'] ?: '—' }}</td>
                <td class="px-4 py-3">{{ $row['quantity'] === null ? __('Unknown') : \App\Support\UiFormat::number($row['quantity']) }}</td>
                <td class="px-4 py-3">{{ $row['value'] === null ? __('Unknown') : \App\Support\UiFormat::number($row['value'], 2).' USD' }}</td>
                <td class="px-4 py-3">@foreach ($row['findings'] as $finding)<div class="text-xs">{{ __($finding) }}</div>@endforeach</td>
            </tr>
        @endforeach
    </x-data-table>
@endif
