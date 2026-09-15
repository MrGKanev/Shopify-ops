@extends('layouts.app')

@section('content')
    <div class="flex max-w-3xl flex-col gap-6">
        <x-page-header eyebrow="Administration · Stores" :title="'Edit '.$store->label" />

        <form class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900" method="POST" action="{{ route('admin.stores.update', $store) }}">
            @csrf
            @method('PUT')
            @include('admin.stores.partials.form', ['store' => $store])
        </form>
    </div>
@endsection
