{{-- Include once above the missing-orders table; each row's checkbox uses form="bulk-ignore" to submit here. --}}
<form id="bulk-ignore" method="POST" action="{{ route('ignored-orders.bulk-store') }}" class="flex flex-wrap items-center gap-2">
    @csrf
    <input class="rounded border px-3 py-2" name="reason" placeholder="Reason for ignoring selected orders">
    <button class="rounded bg-indigo-600 px-4 py-2 text-white">Ignore selected</button>
</form>
