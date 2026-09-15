@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Banned IPs" subtitle="IP addresses locked out after 3 failed login attempts within an hour. Bans last 1 week." />

        <x-data-table :headers="['IP', 'Attempts', 'Banned until', '']">
            @forelse ($bannedIps as $entry)
                <tr>
                    <td class="px-4 py-3 font-mono">{{ $entry->ip }}</td>
                    <td class="px-4 py-3">{{ $entry->attempts }}</td>
                    <td class="px-4 py-3">{{ $entry->banned_until->toDayDateTimeString() }}</td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" action="{{ route('admin.banned-ips.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="ip" value="{{ $entry->ip }}">
                            <x-button type="submit" size="sm" variant="danger">Unban</x-button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">No banned IPs.</td></tr>
            @endforelse
        </x-data-table>
    </div>
@endsection
