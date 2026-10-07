@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Operations" title="{{ __('Review order changes') }}" subtitle="Only eligible orders will be changed. Current data is checked again by the queued job.">
            <x-button size="sm" variant="ghost" :href="route('orders.remediation.create')">{{ __('New preview') }}</x-button>
            <x-button size="sm" variant="ghost" :href="route('orders.remediation.show', $group)">{{ __('Refresh status') }}</x-button>
        </x-page-header>
        @if ($errors->any())
            <x-alert tone="error"><ul class="list-disc pl-5">@foreach ($errors->all() as $message)<li>{{ __($message) }}</li>@endforeach</ul></x-alert>
        @endif
        @foreach ($runs as $run)
            <x-card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold">{{ $run->order_number }} · {{ __($actions[$run->action] ?? $run->action) }}</h2>
                    <x-badge :tone="$run->status === 'completed' ? 'ok' : (in_array($run->status, ['failed', 'blocked'], true) ? 'danger' : 'info')">{{ __($run->status) }}</x-badge>
                </div>
                @if ($run->result_message)<p class="mt-3 text-sm">{{ __($run->result_message) }}</p>@endif
                @if ($run->status === 'preparing')<p class="mt-3 text-sm">{{ __('Preparing current order data in the background. Refresh this page to review the changes when ready.') }}</p>@endif
                @if ($run->status === 'draft' && $run->expires_at->isPast())<x-alert class="mt-3" tone="warn">{{ __('Preview expired. Create a new preview.') }}</x-alert>@endif
                @if (is_array(data_get($run->plan, 'steps')))
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __(':done / :total steps confirmed', ['done' => $run->completed_steps, 'total' => count($run->plan['steps'])]) }}</p>
                    @if ($run->status === 'draft')
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Preview expires at :time', ['time' => $run->expires_at->format('H:i:s')]) }}</p>
                        @if ($run->plan['diff'])
                            <x-data-table class="mt-3" :headers="['Field', 'Shopify', 'ShipStation']">
                                @foreach ($run->plan['diff'] as $field => $values)
                                    <tr><td class="px-4 py-3">{{ $field }}</td><td class="px-4 py-3 break-all">{{ is_array($values['shopify']) ? json_encode($values['shopify'], JSON_UNESCAPED_UNICODE) : $values['shopify'] }}</td><td class="px-4 py-3 break-all">{{ is_array($values['shipstation']) ? json_encode($values['shipstation'], JSON_UNESCAPED_UNICODE) : $values['shipstation'] }}</td></tr>
                                @endforeach
                            </x-data-table>
                        @endif
                        @foreach ($run->plan['steps'] as $step)
                            <details class="mt-3 rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                                <summary class="cursor-pointer text-sm font-medium">{{ __('Step :number', ['number' => $loop->iteration]) }} · {{ __($step['description']) }}</summary>
                                <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-all text-xs">{{ json_encode($step['variables'] ?? $step, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endforeach
                    @endif
                @endif
                @if ($run->action === 'refresh_import' && $run->status === 'completed')
                    <x-button class="mt-3" size="sm" :href="route('orders.remediation.create', ['order_number' => $run->order_number, 'action' => 'push_missing'])">{{ __('Review missing order after import') }}</x-button>
                @endif
            </x-card>
        @endforeach
        @if ($canConfirm)
            <x-card>
                <form method="POST" action="{{ route('orders.remediation.store') }}" class="flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="group_uuid" value="{{ $group }}">
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1 rounded">{{ __('I reviewed the changes for all eligible orders and want to apply them. A failed operation can leave completed steps in place; review its result before trying again.') }}</label>
                    <div><x-button type="submit">{{ __('Apply reviewed changes') }}</x-button></div>
                </form>
            </x-card>
        @endif
    </div>
@endsection
