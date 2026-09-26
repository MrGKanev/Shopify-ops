@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Action Log" subtitle="Configuration changes and day-to-day operator activity, newest first." />

        <x-card>
            <form class="flex flex-wrap items-end gap-3" method="GET">
                <div class="flex flex-col gap-2">
                    <label class="text-sm font-medium" for="category">{{ __('Category') }}</label>
                    <select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="category" name="category" onchange="this.form.submit()">
                        <option value="administration" @selected($category === 'administration')>Administration</option>
                        <option value="operator" @selected($category === 'operator')>{{ __('Operator activity') }}</option>
                        <option value="all" @selected($category === 'all')>All</option>
                    </select>
                </div>
                <x-button type="submit">{{ __('Filter') }}</x-button>
            </form>
        </x-card>

        <x-data-table :headers="['When', 'Actor', 'Action', 'Target', 'Changes']">
            @forelse ($activities as $activity)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $activity->created_at?->toDateTimeString() }}</td>
                    <td class="px-4 py-3">{{ $activity->causer?->email ?? 'System' }}</td>
                    <td class="px-4 py-3">{{ $activity->description }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ class_basename((string) $activity->subject_type) }} #{{ $activity->subject_id }}</td>
                    <td class="px-4 py-3"><pre class="max-w-xl whitespace-pre-wrap text-xs">{{ json_encode(['changes' => $activity->attribute_changes, 'context' => $activity->properties], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">{{ __('No actions recorded for this category.') }}</td></tr>
            @endforelse
        </x-data-table>

        {{ $activities->links() }}
    </div>
@endsection
