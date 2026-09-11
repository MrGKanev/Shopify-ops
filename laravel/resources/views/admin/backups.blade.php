@extends('layouts.app')
@section('content')
<div class="flex flex-col gap-6"><section><h1 class="text-3xl font-bold">Backups</h1><p class="text-slate-500">Archives written by the scheduled backup:run command. Download to store a copy off the server.</p></section><div class="overflow-x-auto rounded-xl border">
<table class="min-w-full divide-y text-left text-sm"><thead><tr><th class="px-4 py-3 font-semibold">File</th><th class="px-4 py-3 font-semibold">Size</th><th class="px-4 py-3 font-semibold">Created</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y">
@forelse ($backups as $backup)
<tr><td class="px-4 py-3 font-mono">{{ $backup['name'] }}</td><td class="px-4 py-3">{{ \Illuminate\Support\Number::fileSize($backup['size']) }}</td><td class="px-4 py-3">{{ $backup['lastModified']->toDayDateTimeString() }}</td><td class="px-4 py-3"><a class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500" href="{{ route('admin.backups.download', $backup['path']) }}">Download</a></td></tr>
@empty
<tr><td class="px-4 py-3 text-slate-500" colspan="4">No backups found.</td></tr>
@endforelse
</tbody></table></div></div>
@endsection
