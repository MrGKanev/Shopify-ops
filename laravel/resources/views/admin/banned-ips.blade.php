@extends('layouts.app')
@section('content')
<div class="flex flex-col gap-6"><section><h1 class="text-3xl font-bold">Banned IPs</h1><p class="text-slate-500">IP addresses locked out after 3 failed login attempts within an hour. Bans last 1 week.</p></section>@if(session('status'))<div class="rounded-xl bg-green-50 p-4">{{ session('status') }}</div>@endif<div class="overflow-x-auto rounded-xl border">
<table class="min-w-full divide-y text-left text-sm"><thead><tr><th class="px-4 py-3 font-semibold">IP</th><th class="px-4 py-3 font-semibold">Attempts</th><th class="px-4 py-3 font-semibold">Banned until</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y">
@forelse ($bannedIps as $entry)
<tr><td class="px-4 py-3 font-mono">{{ $entry->ip }}</td><td class="px-4 py-3">{{ $entry->attempts }}</td><td class="px-4 py-3">{{ $entry->banned_until->toDayDateTimeString() }}</td><td class="px-4 py-3"><form method="POST" action="{{ route('admin.banned-ips.destroy') }}">@csrf @method('DELETE')<input type="hidden" name="ip" value="{{ $entry->ip }}"><button class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500" type="submit">Unban</button></form></td></tr>
@empty
<tr><td class="px-4 py-3 text-slate-500" colspan="4">No banned IPs.</td></tr>
@endforelse
</tbody></table></div></div>
@endsection
