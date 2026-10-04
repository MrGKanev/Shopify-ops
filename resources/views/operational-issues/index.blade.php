@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Issue triage') }}" :subtitle="__(':count open or in-progress issues for :store', ['count' => $openCount, 'store' => $activeStore->label])">
            <x-button size="sm" :href="route('reports.run-audit')">{{ __('Run audit') }}</x-button>
        </x-page-header>

        <div class="flex flex-wrap gap-2">
            @foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $value => $label)
                @php($filters = array_filter(['status' => $value ?: null, 'mine' => $mine ? 1 : null, 'overdue' => $overdue ? 1 : null, 'source' => $source ?: null]))
                <a class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $status === $value ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' }}" href="{{ route('operational-issues.index', $filters) }}">{{ __($label) }}</a>
            @endforeach
            @foreach (['mine' => ['My issues', $mine], 'overdue' => ['Overdue', $overdue]] as $filter => [$label, $active])
                @php($filters = array_filter(['status' => $status ?: null, 'mine' => $filter === 'mine' ? ($active ? null : 1) : ($mine ? 1 : null), 'overdue' => $filter === 'overdue' ? ($active ? null : 1) : ($overdue ? 1 : null), 'source' => $source ?: null]))
                <a class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $active ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' }}" href="{{ route('operational-issues.index', $filters) }}">{{ __($label) }}</a>
            @endforeach
            @if ($source === 'delivery_watch') <a class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white" href="{{ route('operational-issues.index', array_filter(['status' => $status ?: null, 'mine' => $mine ? 1 : null, 'overdue' => $overdue ? 1 : null])) }}">{{ __('Delivery exceptions') }}</a> @else <a class="rounded-lg bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300" href="{{ route('operational-issues.index', array_filter(['status' => $status ?: null, 'mine' => $mine ? 1 : null, 'overdue' => $overdue ? 1 : null, 'source' => 'delivery_watch'])) }}">{{ __('Delivery exceptions') }}</a> @endif
        </div>

        <x-data-table :headers="['Issue', 'Priority', 'Owner', 'Due', 'Seen', 'Status', 'Action']">
            @forelse ($issues as $issue)
                <tr id="issue-{{ $issue->id }}">
                    <td class="px-4 py-3"><div class="font-semibold">{{ \App\Support\UiFormat::text($issue->title) }}</div><div class="text-xs text-slate-500 dark:text-slate-400">{{ $issue->source_tool }} · {{ trans_choice('{1} :count occurrence|[2,*] :count occurrences', $issue->occurrences) }}</div>@if ($issue->reference && in_array($issue->source_tool, ['run_audit', 'shopify_webhook'], true))<a class="mt-1 inline-flex text-xs font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('orders.timeline', ['order_number' => $issue->reference]) }}">{{ __('Open order timeline ·') }} {{ $issue->reference }}</a>@elseif (data_get($issue->payload, 'order_number'))<a class="mt-1 inline-flex text-xs font-medium text-indigo-600 dark:text-indigo-400" href="{{ route('orders.timeline', ['order_number' => data_get($issue->payload, 'order_number')]) }}">{{ __('Open order timeline ·') }} {{ data_get($issue->payload, 'order_number') }}</a>@endif
                        @if ($issue->source_tool === 'order_changed_after_push')
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Verify the items and shipping details in ShipStation before fulfillment.') }}</p>
                        @elseif ($issue->source_tool === 'repeated_shipping_address')
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Warning only: :count recent orders share this address; :names distinct recipient names. No orders are blocked.', ['count' => data_get($issue->payload, 'order_count'), 'names' => data_get($issue->payload, 'different_names')]) }}</p>
                            <div class="mt-1 flex flex-wrap gap-2">
                            @foreach (data_get($issue->payload, 'order_numbers', []) as $orderNumber)
                                <a class="text-xs text-indigo-600 dark:text-indigo-400" href="{{ route('orders.timeline', ['order_number' => $orderNumber]) }}">{{ $orderNumber }}</a>
                            @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-badge :tone="$issue->priority->tone()">{{ $issue->priority->value }}</x-badge></td>
                    <td class="px-4 py-3">{{ $issue->owner?->name ?? __('Unassigned') }}</td>
                    <td class="px-4 py-3">@if ($issue->due_date) <span class="{{ $issue->due_date->isBefore(today()) && $issue->status->isActive() ? 'font-semibold text-red-600 dark:text-red-400' : '' }}">{{ \App\Support\UiFormat::date($issue->due_date) }}</span>@if ($issue->due_date->isBefore(today()) && $issue->status->isActive()) <x-badge tone="danger">{{ __('Overdue') }}</x-badge> @endif @else — @endif</td>
                    <td class="px-4 py-3">{{ \App\Support\UiFormat::relative($issue->last_seen_at) }}</td>
                    <td class="px-4 py-3">{{ __($issue->status->label()) }}</td>
                    <td class="px-4 py-3">
                        <form class="flex min-w-64 flex-col gap-2" method="POST" action="{{ route('operational-issues.update', $issue) }}">
                            @csrf @method('PUT')
                            <div class="flex flex-wrap gap-2">
                            <select class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="status">
                                @foreach (\App\IssueStatus::cases() as $issueStatus)<option value="{{ $issueStatus->value }}" @selected($issue->status === $issueStatus)>{{ __($issueStatus->label()) }}</option>@endforeach
                            </select>
                            <select class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="priority">
                                @foreach (\App\IssuePriority::cases() as $issuePriority)<option value="{{ $issuePriority->value }}" @selected($issue->priority === $issuePriority)>{{ __($issuePriority->label()) }}</option>@endforeach
                            </select>
                            <select class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="owner_user_id"><option value="">{{ __('Unassigned') }}</option>@foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected($issue->owner_user_id === $owner->id)>{{ $owner->name }}</option>@endforeach</select>
                            <x-button size="sm" type="submit">{{ __('Save') }}</x-button>
                            </div>
                            <label class="flex flex-col gap-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Due date') }}<input class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="due_date" type="date" value="{{ $issue->due_date?->toDateString() }}"></label>
                            <label class="flex flex-col gap-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Resolution note') }}<textarea class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" name="resolution_note" maxlength="1000" rows="2">{{ $issue->resolution_note }}</textarea></label>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="7">{{ __('No issues match this filter.') }}</td></tr>
            @endforelse
        </x-data-table>

        {{ $issues->links() }}
    </div>
@endsection
