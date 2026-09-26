@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="Audits" subtitle="Every audit and report, grouped by category." />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (config('audit-hub') as $category => $links)
                <x-card>
                    <div class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">{{ __($category) }}</div>
                    <ul class="flex flex-col gap-1.5 text-sm">
                        @foreach ($links as $link)
                            <li><a class="text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route($link['route']) }}">{{ __($link['label']) }}</a></li>
                        @endforeach
                    </ul>
                </x-card>
            @endforeach
        </div>
    </div>
@endsection
