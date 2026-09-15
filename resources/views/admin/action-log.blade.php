@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Action Log" subtitle="Administrative changes, newest first." />

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
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No administrative actions recorded.</td></tr>
            @endforelse
        </x-data-table>

        {{ $activities->links() }}
    </div>
@endsection
