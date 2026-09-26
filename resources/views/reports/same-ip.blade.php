@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Risk report" title="Same IP, Different Emails" subtitle="Find paid orders where two or more distinct customer emails share the exact client IP. Shared networks can be legitimate, so treat clusters as review signals." />

        <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('reports.same-ip.store') }}">
            @csrf
            @foreach (['start_date' => ['From', $startDate], 'end_date' => ['To', $endDate]] as $field => [$label, $value])
                <div>
                    <label class="text-sm font-medium" for="{{ $field }}">{{ $label }}</label>
                    <input class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $value) }}">
                    @error($field)
                        <p class="text-sm text-red-600">{{ __($message) }}</p>
                    @enderror
                </div>
            @endforeach
            <div class="flex items-end">
                <x-button type="submit">{{ __('Run report') }}</x-button>
            </div>
        </form>

        @if ($configurationError)
            <x-alert tone="warn">{{ __('Shopify credentials are incomplete for the active store.') }}</x-alert>
        @endif
        @if ($reportFailed)
            <x-alert tone="error">{{ __('The report could not be completed. Check Shopify and try again.') }}</x-alert>
        @endif

        @if ($result)
            <section class="flex flex-col gap-4">
                <h2 class="text-2xl font-bold">{{ $result->scanned }} scanned · {{ count($result->rows) }} shared IPs</h2>

                @if ($result->truncated)
                    <x-alert tone="warn">{{ __('Results are incomplete: orders truncated after :pages pages.', ['pages' => $result->pages]) }}</x-alert>
                @endif

                <x-data-table :headers="['Client IP', 'Emails', 'Orders', 'Details']">
                    @forelse ($result->rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold">{{ $row['ip'] }}</td>
                            <td class="px-4 py-3">
                                {{ $row['email_count'] }}
                                <ul>
                                    @foreach ($row['emails'] as $email)
                                        <li>{{ $email }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-4 py-3">{{ $row['order_count'] }}</td>
                            <td class="px-4 py-3">
                                <ul>
                                    @foreach ($row['orders'] as $order)
                                        <li>
                                            @if ($order['id'])
                                                <a class="text-indigo-600 dark:text-indigo-400" href="https://{{ $activeStore->shopify_store }}.myshopify.com/admin/orders/{{ $order['id'] }}" target="_blank" rel="noopener noreferrer">{{ $order['number'] }}</a>
                                            @else
                                                {{ $order['number'] }}
                                            @endif
                                            · {{ $order['created_at'] }} · {{ $order['email'] }} · {{ number_format($order['total'], 2) }} {{ $order['currency'] }}
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-slate-500" colspan="4">{{ __('Every client IP in this range was used by only one customer email.') }}</td>
                        </tr>
                    @endforelse
                </x-data-table>
            </section>
        @endif
    </div>
@endsection
