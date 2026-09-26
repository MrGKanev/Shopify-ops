@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header eyebrow="Administration" title="Users">
            <x-button :href="route('admin.users.create')">Add user</x-button>
        </x-page-header>

        <x-data-table :headers="['User', 'Role', 'Stores', 'Action']">
            @forelse ($users as $user)
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-semibold">{{ $user->name }}</p>
                        <p class="text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                    </td>
                    <td class="px-4 py-3 capitalize">{{ $user->role->value }}</td>
                    <td class="px-4 py-3">{{ $user->stores_count }}</td>
                    <td class="px-4 py-3 text-right"><x-button :href="route('admin.users.edit', $user)" size="sm" variant="ghost">Edit</x-button></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-500 dark:text-slate-400" colspan="4">{{ __('No users configured.') }}</td></tr>
            @endforelse
        </x-data-table>

        @if ($users->hasPages())
            {{ $users->links() }}
        @endif
    </div>
@endsection
