@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="{{ __('Carrier claims') }}" subtitle="{{ __('Internal register of loss and damage claims. Statuses, dates and amounts are entered by staff from the carrier or insurer portal; nothing is submitted or synchronized automatically.') }}">
            <x-button :href="route('carrier-claims.create')">{{ __('New claim') }}</x-button>
        </x-page-header>

        <x-card>
            <form class="flex flex-wrap items-end gap-4" method="GET" action="{{ route('carrier-claims.index') }}">
                <div>
                    <label class="text-sm font-medium" for="status">{{ __('Status') }}</label>
                    <select class="mt-2 rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="status" name="status">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($statuses as $value)<option value="{{ $value }}" @selected($status === $value)>{{ __(ucfirst(str_replace('_', ' ', $value))) }}</option>@endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 pb-2"><input type="checkbox" name="overdue" value="1" @checked($overdue)> {{ __('Follow-up overdue') }}</label>
                <x-button type="submit">{{ __('Filter') }}</x-button>
                <a class="pb-2 font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('carrier-claims.export') }}">{{ __('Download CSV') }}</a>
            </form>
            @if ($outstanding !== [])
                <p class="mt-4 text-sm">{{ __('Approved but not yet received (active claims):') }}
                    @foreach ($outstanding as $currency => $amount)<strong>{{ $amount }} {{ $currency }}</strong>@if (! $loop->last), @endif @endforeach
                </p>
            @endif
        </x-card>

        <x-data-table :headers="['Claim', 'Status', 'Goods (approved / received)', 'Postage (approved / received)', 'Follow-up']">
            @forelse ($rows as $row)
                @php($claim = $row['claim'])
                <tr>
                    <td class="px-4 py-3"><a class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('carrier-claims.show', $claim->id) }}">{{ $claim->provider }} · {{ $claim->external_reference }}</a><div class="text-xs text-slate-500">{{ $claim->order_number ?: '—' }}</div></td>
                    <td class="px-4 py-3"><x-badge :tone="$claim->isActive() ? 'warn' : 'default'">{{ ucfirst(str_replace('_', ' ', $claim->status)) }}</x-badge></td>
                    @foreach (['goods', 'postage'] as $kind)
                        <td class="px-4 py-3">{{ $row['summary'][$kind]['approved'] ?? '—' }} / {{ $row['summary'][$kind]['received'] }} {{ $claim->currency }}@if ($row['summary'][$kind]['over_received'])<x-badge tone="danger">{{ __('Received exceeds approved') }}</x-badge>@endif</td>
                    @endforeach
                    <td class="px-4 py-3">@if ($claim->follow_up_on)<span class="{{ $claim->isActive() && $claim->follow_up_on->isBefore(today()) ? 'font-semibold text-red-600 dark:text-red-400' : '' }}">{{ \App\Support\UiFormat::date($claim->follow_up_on) }}</span>@else — @endif</td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">{{ __('No carrier claims yet.') }}</td></tr>
            @endforelse
        </x-data-table>

        <x-card>
            <h3 class="text-lg font-semibold">{{ __('Verified deadline policies') }}</h3>
            <p class="mt-1 text-sm text-slate-500">{{ __('Copy the rule from the provider terms and link to them. The app does not assume any claim window.') }}</p>
            <ul class="mt-3 space-y-1 text-sm">
                @forelse ($policies as $policy)
                    <li class="flex flex-wrap items-center gap-2">{{ $policy->label() }} <a class="text-indigo-600 hover:underline dark:text-indigo-400" href="{{ $policy->source_url }}" target="_blank" rel="noopener noreferrer">{{ __('Source') }}</a> <span class="text-slate-500">{{ __('verified') }} {{ \App\Support\UiFormat::date($policy->verified_on) }}</span>
                        <form method="POST" action="{{ route('carrier-claims.policies.destroy', $policy->id) }}">@csrf @method('DELETE')<button class="text-red-600 hover:underline dark:text-red-400" type="submit">{{ __('Delete') }}</button></form></li>
                @empty
                    <li class="text-slate-500">{{ __('No policies yet.') }}</li>
                @endforelse
            </ul>
            <form class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" method="POST" action="{{ route('carrier-claims.policies.store') }}">
                @csrf
                @foreach ([['provider', 'Provider', true], ['account', 'Account', false], ['service', 'Service', false], ['description', 'Deadline rule as stated by the provider', true], ['source_url', 'Source link (https)', true]] as [$field, $label, $required])
                    <div>
                        <label class="text-sm font-medium" for="policy_{{ $field }}">{{ __($label) }}</label>
                        <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="policy_{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" @required($required)>
                        @error($field)<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div>
                    <label class="text-sm font-medium" for="policy_verified_on">{{ __('Verified on') }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="policy_verified_on" name="verified_on" type="date" required value="{{ old('verified_on') }}">
                    @error('verified_on')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2 lg:col-span-3"><x-button type="submit">{{ __('Save policy') }}</x-button></div>
            </form>
        </x-card>
    </div>
@endsection
