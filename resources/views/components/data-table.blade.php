{{--
    Wraps a <table>; caller keeps its own @forelse/@empty rows in the default slot.
    Pass `:headers="['Order', 'Date']"` for plain column labels, or a `head` slot
    for anything richer (sort links, icon-only columns, a leading checkbox column).
--}}
@props(['headers' => null])
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900']) }}>
    <table class="min-w-full text-left text-sm">
        <thead>
            <tr>
                @if ($headers)
                    @foreach ($headers as $header)
                        <th class="px-4 py-3">{{ $header }}</th>
                    @endforeach
                @else
                    {{ $head }}
                @endif
            </tr>
        </thead>
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
