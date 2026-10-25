@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" :title="__('Report schedules')" subtitle="Control each report independently. Pausing stops future and queued automatic runs; a running report can finish. Run now also works while paused." />
        <div class="flex flex-wrap gap-3">
            <x-button :href="route('admin.report-schedules.create')">{{ __('Schedule backlog history') }}</x-button>
            <x-button variant="ghost" :href="route('admin.report-schedules.create', ['tool' => 'shopify_backlog'])">{{ __('Schedule Shopify backlog') }}</x-button>
            <x-button variant="ghost" :href="route('admin.report-schedules.create', ['tool' => 'shipment_movement_monitor'])">{{ __('Schedule tracking monitoring') }}</x-button>
            <x-button variant="ghost" :href="route('admin.report-schedules.create', ['tool' => 'run_audit'])">{{ __('Schedule Run Audit') }}</x-button>
            <x-button variant="ghost" :href="route('audits.index')">{{ __('Choose another report') }}</x-button>
        </div>
        <x-alert tone="warn">{{ __('For another report, run it with your desired filters and choose Schedule with these filters from its results. Complete reports without pagination cursors can be scheduled. A scheduler and queue worker must be running.') }}</x-alert>
        @if ($errors->any())<x-alert tone="error">{{ $errors->first() }}</x-alert>@endif
        <x-data-table :headers="['Report', 'Schedule', 'Next run', 'Last run', 'Notifications', 'Actions']" :rows="$schedules" empty="No report schedules yet.">
            @foreach ($schedules as $schedule)
                @php($last = $schedule->latestRun)
                <tr>
                    <td class="px-4 py-3"><strong>{{ $schedule->name }}</strong><div>{{ __($registry->find($schedule->tool)?->label ?? $schedule->tool) }}</div><x-badge :tone="$schedule->enabled ? 'ok' : 'default'">{{ __($schedule->enabled ? 'Enabled' : 'Paused') }}</x-badge></td>
                    <td class="px-4 py-3">{{ __(ucfirst($schedule->frequency)) }} · {{ $schedule->time }}<div>{{ $schedule->timezone }}</div></td>
                    <td class="px-4 py-3">{{ $schedule->next_run_at?->setTimezone($schedule->timezone)->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($last)
                            <x-badge :tone="$last->hasFailed() ? 'danger' : ($last->status === 'completed' ? 'ok' : 'default')">{{ __($last->status) }}</x-badge>
                            <div>{{ \App\Support\UiFormat::date($last->finished_at ?? $last->created_at, true) }}</div>
                            @if ($last->hasFailed())<p>{{ __($last->failure_reason ?? 'Report execution failed. Check run history.') }}</p>@endif
                            @if ($last->status === 'completed')
                                @if ($last->result()?->truncated)<x-badge tone="warn">{{ __('Incomplete results') }}</x-badge>@endif
                                <a class="text-indigo-600 dark:text-indigo-400" href="{{ route('admin.report-schedules.result', [$schedule->id, $last->id]) }}">{{ __('View results') }}</a>
                            @endif
                        @elseif ($schedule->last_run_status)
                            <x-badge :tone="$schedule->last_run_status === 'failed' ? 'danger' : 'default'">{{ __($schedule->last_run_status) }}</x-badge>
                            <div>{{ \App\Support\UiFormat::date($schedule->last_finished_at, true) }}</div>
                            @if ($schedule->last_run_status === 'failed')<p>{{ __($schedule->last_run_error ?? 'Report execution failed. Check run history.') }}</p>@endif
                            <p>{{ __('Stored result has expired.') }}</p>
                        @else — @endif
                    </td>
                    <td class="px-4 py-3">@if ($schedule->notification_enabled){{ $schedule->notification_email }}<div>{{ __('Minimum findings') }}: {{ $schedule->notification_threshold }}</div>@else{{ __('Off') }}@endif</td>
                    <td class="px-4 py-3"><div class="flex flex-wrap gap-2">
                        <x-button size="sm" variant="ghost" :href="route('admin.report-schedules.edit', $schedule)">{{ __('Edit') }}</x-button>
                        <form method="POST" action="{{ route('admin.report-schedules.toggle', $schedule) }}">@csrf<input type="hidden" name="enabled" value="{{ (int) ! $schedule->enabled }}"><x-button size="sm" variant="ghost" type="submit">{{ __($schedule->enabled ? 'Pause' : 'Enable') }}</x-button></form>
                        <form method="POST" action="{{ route('admin.report-schedules.run', $schedule) }}">@csrf<x-button size="sm" type="submit" :disabled="$last?->isPending() ?? false">{{ __('Run now') }}</x-button></form>
                    </div></td>
                </tr>
            @endforeach
        </x-data-table>
        {{ $schedules->links() }}
    </div>
@endsection
