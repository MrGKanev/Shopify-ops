@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Backups" subtitle="Archives written by the scheduled backup command. Download a copy to store it off the server." />

        <x-card><form class="flex flex-wrap items-end gap-3" method="POST" action="{{ route('admin.backups.store') }}">@csrf <div class="flex flex-col gap-2"><label class="text-sm font-medium" for="scope">Backup type</label><select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="scope" name="scope"><option value="database">Database only</option><option value="full">Full backup (database + files)</option></select></div><x-button type="submit">Create backup now</x-button></form>@error('backup')<p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</x-card>

        @error('verification')<x-alert tone="error">{{ $message }}</x-alert>@enderror

        <x-data-table :headers="['File', 'Size', 'Created', 'Restore check', '']">
            @forelse ($backups as $backup)
                @php($verification = $latestVerifications->get($backup['path']))
                <tr>
                    <td class="px-4 py-3 font-mono">{{ $backup['name'] }}</td>
                    <td class="px-4 py-3">{{ \Illuminate\Support\Number::fileSize($backup['size']) }}</td>
                    <td class="px-4 py-3">{{ $backup['lastModified']->toDayDateTimeString() }}</td>
                    <td class="px-4 py-3">@if ($verification)<x-badge :tone="$verification->status === 'verified' ? 'ok' : 'danger'">{{ $verification->status === 'verified' ? 'Verified' : 'Failed' }}</x-badge><div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $verification->verified_at->diffForHumans() }} · {{ $verification->duration_ms }} ms</div>@else<span class="text-sm text-slate-500 dark:text-slate-400">Not checked</span>@endif</td>
                    <td class="px-4 py-3"><div class="flex justify-end gap-2"><form method="POST" action="{{ route('admin.backups.verify') }}">@csrf<input type="hidden" name="path" value="{{ $backup['path'] }}"><x-button type="submit" size="sm" variant="ghost">Verify restore</x-button></form><x-button :href="route('admin.backups.download', $backup['path'])" size="sm">Download</x-button></div></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No backups found.</td></tr>
            @endforelse
        </x-data-table>
    </div>
@endsection
