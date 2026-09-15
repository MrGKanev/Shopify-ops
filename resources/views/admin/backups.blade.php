@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Backups" subtitle="Archives written by the scheduled backup command. Download a copy to store it off the server." />

        <x-data-table :headers="['File', 'Size', 'Created', '']">
            @forelse ($backups as $backup)
                <tr>
                    <td class="px-4 py-3 font-mono">{{ $backup['name'] }}</td>
                    <td class="px-4 py-3">{{ \Illuminate\Support\Number::fileSize($backup['size']) }}</td>
                    <td class="px-4 py-3">{{ $backup['lastModified']->toDayDateTimeString() }}</td>
                    <td class="px-4 py-3 text-right"><x-button :href="route('admin.backups.download', $backup['path'])" size="sm">Download</x-button></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">No backups found.</td></tr>
            @endforelse
        </x-data-table>
    </div>
@endsection
