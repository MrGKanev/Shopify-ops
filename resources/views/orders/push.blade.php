@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <p class="text-sm font-medium text-amber-600 dark:text-amber-400">Write actions — changes ShipStation and Shopify</p>
            <h1 class="text-3xl font-bold">Push order / fix note</h1>
            <p class="text-slate-500 dark:text-slate-400">Manually create a Shopify order in ShipStation when the automatic sync missed it, or correct an order's Shopify note.</p>
        </section>

        @if (session('status'))
            <div class="rounded-xl bg-emerald-50 p-4 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</div>
        @endif

        <section class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-xl font-bold">Push to ShipStation</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Fetches the order from Shopify and creates it in ShipStation as <code>awaiting_shipment</code>. This creates a real order — preview the payload first.</p>

            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" method="POST" action="{{ route('orders.push.store') }}" id="push-form">
                @csrf
                <div class="flex grow flex-col gap-2">
                    <label class="text-sm font-medium" for="push_order_number">Order number</label>
                    <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="push_order_number" name="order_number" value="{{ old('order_number') }}" placeholder="#65075" maxlength="64">
                    @error('order_number')
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <button class="rounded-lg border border-slate-300 px-5 py-2.5 font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" type="button" id="push-preview-btn">Preview payload</button>
                <button class="rounded-lg bg-amber-600 px-5 py-2.5 font-semibold text-white hover:bg-amber-500" type="submit" onclick="return confirm('Create this order in ShipStation now?')">Push to ShipStation</button>
            </form>
            <pre id="push-preview-output" class="hidden overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-950"></pre>
        </section>

        <script>
            document.getElementById('push-preview-btn')?.addEventListener('click', async () => {
                const form = document.getElementById('push-form');
                const output = document.getElementById('push-preview-output');
                const response = await fetch('{{ route('orders.push.preview') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                    body: new URLSearchParams(new FormData(form)),
                });
                const data = await response.json();
                output.textContent = JSON.stringify(data, null, 2);
                output.classList.remove('hidden');
            });
        </script>

        <section class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-xl font-bold">Save order note</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Replaces the Shopify order's note. Look up the order first to find its numeric order ID.</p>

            <form class="grid gap-3" method="POST" action="{{ route('orders.note.update') }}">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="note_order_number">Order number</label>
                        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="note_order_number" name="order_number" value="{{ old('order_number') }}" placeholder="#65075" maxlength="64">
                        @error('order_number')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-medium" for="order_id">Shopify order ID</label>
                        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="order_id" name="order_id" value="{{ old('order_id') }}" placeholder="65075001" maxlength="20">
                        @error('order_id')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <label class="text-sm font-medium" for="note">Note</label>
                    <textarea class="min-h-24 rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none ring-indigo-500 focus:ring-2 dark:border-slate-700 dark:bg-slate-950" id="note" name="note" maxlength="5000">{{ old('note') }}</textarea>
                    @error('note')
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <button class="rounded-lg bg-amber-600 px-5 py-2.5 font-semibold text-white hover:bg-amber-500" type="submit">Save note</button>
                </div>
            </form>
        </section>
    </div>
@endsection
