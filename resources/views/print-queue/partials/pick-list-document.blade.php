<article class="rounded-xl border border-slate-300 bg-white p-6 text-slate-950 {{ ['large' => 'text-xl', 'xlarge' => 'text-3xl'][$size] ?? '' }}">
    <div class="flex items-start justify-between">
        <div><h2 class="text-3xl font-bold">{{ __('Pick List') }}</h2><p>{{ $result['orderCount'] }} {{ __('orders') }} · {{ $result['totalQuantity'] }} {{ __('units') }}</p></div>
        <button class="print-hidden rounded-lg bg-slate-900 px-4 py-2 text-white" type="button" data-print-window>{{ __('Print') }}</button>
    </div>
    <table class="mt-6 w-full border-collapse">
        <thead><tr class="border-b">@foreach ($columns as $column)<th class="py-2 pr-3 text-left {{ $column === 'quantity' ? 'text-right' : '' }}">{{ __($columnLabels[$column]) }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($result['lines'] as $line)
                <tr class="border-b align-top">
                    @foreach ($columns as $column)
                        <td class="py-3 pr-3 {{ $column === 'quantity' ? 'text-right font-bold' : '' }}">
                            @if ($column === 'orders')
                                @foreach ($line['allocations'] as $allocation)<div>{{ $allocation['order'] }} × {{ $allocation['quantity'] }}</div>@endforeach
                            @elseif ($column === 'image')
                                @if (str_starts_with($line['image'], 'https://'))<img class="{{ ['large' => 'h-24 w-24', 'xlarge' => 'h-40 w-40'][$size] ?? 'h-12 w-12' }} object-contain" src="{{ $line['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif
                            @else
                                {{ $line[$column] !== '' ? $line[$column] : '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($customerContexts !== [])
        <section class="mt-6"><h3 class="font-bold uppercase">{{ __('Customer context') }}</h3>
            @foreach ($customerContexts as $row)<div>{{ $row['order'] }} — @include('print-queue.partials.context-summary', ['row' => $row])</div>@endforeach
        </section>
    @endif
</article>
