@extends('layouts.app')
@section('content')
<div class="flex flex-col gap-6"><section><h1 class="text-3xl font-bold">Failed Jobs</h1><p class="text-slate-500">Queue jobs that threw an exception. Retry pushes the job back onto its original queue; delete discards it.</p></section>@if(session('status'))<div class="rounded-xl bg-green-50 p-4">{{ session('status') }}</div>@endif<div class="overflow-x-auto rounded-xl border">
<table class="min-w-full divide-y text-left text-sm"><thead><tr><th class="px-4 py-3 font-semibold">Job</th><th class="px-4 py-3 font-semibold">Queue</th><th class="px-4 py-3 font-semibold">Failed at</th><th class="px-4 py-3 font-semibold">Exception</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y">
@forelse ($failedJobs as $job)
<tr><td class="px-4 py-3 font-mono">{{ $job->displayName }}</td><td class="px-4 py-3">{{ $job->queue }}</td><td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->toDayDateTimeString() }}</td><td class="px-4 py-3 text-slate-500">{{ $job->exceptionSummary }}</td><td class="px-4 py-3 whitespace-nowrap"><form class="inline" method="POST" action="{{ route('admin.failed-jobs.retry', $job->id) }}">@csrf<button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500" type="submit">Retry</button></form> <form class="inline" method="POST" action="{{ route('admin.failed-jobs.destroy', $job->id) }}">@csrf @method('DELETE')<button class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500" type="submit">Delete</button></form></td></tr>
@empty
<tr><td class="px-4 py-3 text-slate-500" colspan="5">No failed jobs.</td></tr>
@endforelse
</tbody></table></div></div>
@endsection
