@extends('layouts.app')

@section('content')
    <style @cspNonce>@media print { header, aside, .print-hidden { display: none !important; } main { width: 100%; } body { background: white; color: black; } }</style>
    <div class="flex flex-col gap-6">
        <div class="print-hidden"><x-page-header title="{{ __('Pick List') }}" subtitle="{{ __('Snapshot saved :date. It does not change when orders or templates change.', ['date' => $snapshot->created_at->format('Y-m-d H:i')]) }}" /></div>

        <div class="print-hidden flex flex-wrap items-center gap-4">
            <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('print-queue.pick-list') }}">{{ __('Back to pick list') }}</a>
            <a class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('print-queue.pick-list.snapshots.export', $snapshot->id) }}">{{ __('Download CSV') }}</a>
            <form method="POST" action="{{ route('print-queue.pick-list.snapshots.destroy', $snapshot->id) }}">@csrf @method('DELETE')<x-button type="submit" variant="danger">{{ __('Delete snapshot') }}</x-button></form>
        </div>

        @if ($snapshot->payload['missing'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('Not found in ShipStation, excluded from totals:') }} {{ implode(', ', $snapshot->payload['missing']) }}</x-alert>@endif
        @if ($snapshot->payload['ambiguous'] !== [])<x-alert tone="warn" class="print-hidden">{{ __('More than one exact match, excluded from totals:') }} {{ implode(', ', $snapshot->payload['ambiguous']) }}</x-alert>@endif
        @foreach ($snapshot->payload['warnings'] as $warning)<x-alert tone="warn" class="print-hidden">{{ $warning }}</x-alert>@endforeach

        @include('print-queue.partials.pick-list-document', ['result' => $snapshot->payload, 'columns' => $snapshot->columns, 'size' => $snapshot->size, 'columnLabels' => $columnLabels, 'customerContexts' => []])
    </div>
@endsection
