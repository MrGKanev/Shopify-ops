@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Backups" subtitle="Архивите се създават по график. Сваляй копие извън сървъра за допълнителна защита." />

        <x-card><form class="flex flex-wrap items-end gap-3" method="POST" action="{{ route('admin.backups.store') }}">@csrf <div class="flex flex-col gap-2"><label class="text-sm font-medium" for="scope">Тип архив</label><select class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="scope" name="scope"><option value="database">Само базата</option><option value="full">База и файлове</option></select></div><x-button type="submit">Създай архив</x-button></form>@error('backup')<p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</x-card>

        @error('verification')<x-alert tone="error">{{ $message }}</x-alert>@enderror
        @error('restore')<x-alert tone="error">{{ $message }}</x-alert>@enderror

        @if (session('status'))<x-alert tone="ok">{{ session('status') }}</x-alert>@endif

        @if ($operations->isNotEmpty())
            <x-card>
                <h2 class="font-semibold">Последни операции по възстановяване</h2>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($operations as $operation)
                        <div class="text-sm">
                            <span class="font-mono">{{ basename($operation['path']) }}</span>
                            <x-badge :tone="$operation['status'] === 'completed' ? 'ok' : ($operation['status'] === 'failed' ? 'danger' : 'warn')">{{ ['queued' => 'Изчаква', 'running' => 'В процес', 'completed' => 'Готово', 'failed' => 'Неуспешно'][$operation['status']] ?? $operation['status'] }}</x-badge>
                            @if ($operation['status'] === 'completed' && ! empty($operation['safety_backup']))<span class="text-slate-500 dark:text-slate-400">Предпазен архив: {{ basename($operation['safety_backup']) }}</span>@endif
                            @if ($operation['status'] === 'failed')<span class="text-red-600 dark:text-red-400">{{ $operation['error'] }}</span>@endif
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        <x-data-table :headers="['File', 'Size', 'Created', 'Restore check', '']">
            @forelse ($backups as $backup)
                @php($verification = $latestVerifications->get($backup['path']))
                <tr>
                    <td class="px-4 py-3 font-mono">{{ $backup['name'] }}</td>
                    <td class="px-4 py-3">{{ \Illuminate\Support\Number::fileSize($backup['size']) }}</td>
                    <td class="px-4 py-3">{{ $backup['lastModified']->toDayDateTimeString() }}</td>
                    <td class="px-4 py-3">@if ($verification)<x-badge :tone="$verification->status === 'verified' ? 'ok' : 'danger'">{{ $verification->status === 'verified' ? 'Verified' : 'Failed' }}</x-badge><div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $verification->verified_at->diffForHumans() }} · {{ $verification->duration_ms }} ms</div>@else<span class="text-sm text-slate-500 dark:text-slate-400">Not checked</span>@endif</td>
                    <td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><form method="POST" action="{{ route('admin.backups.verify') }}">@csrf<input type="hidden" name="path" value="{{ $backup['path'] }}"><x-button type="submit" size="sm" variant="ghost">Провери</x-button></form>@if ($verification?->status === 'verified')<form class="flex flex-wrap items-center justify-end gap-2" method="POST" action="{{ route('admin.backups.restore') }}" onsubmit="return confirm('Това ще замени базата и файловете със същото име от архива. Останалите файлове ще се запазят. Преди това ще бъде направен предпазен архив. Продължаваме?')">@csrf<input type="hidden" name="path" value="{{ $backup['path'] }}"><label class="sr-only" for="restore-confirm-{{ $loop->index }}">Въведи името на архива за потвърждение</label><input class="w-40 rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-950" id="restore-confirm-{{ $loop->index }}" name="confirmation" placeholder="Име на архива" required><x-button type="submit" size="sm" variant="danger">Възстанови</x-button></form>@endif<x-button :href="route('admin.backups.download', $backup['path'])" size="sm">Изтегли</x-button></div></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="5">No backups found.</td></tr>
            @endforelse
        </x-data-table>
    </div>
@endsection
