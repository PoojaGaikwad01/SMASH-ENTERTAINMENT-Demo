<x-layouts.app title="Counter lookup">
    <form method="GET" class="card mb-4 flex max-w-3xl gap-2 p-4">
        <input type="search" name="q" value="{{ $q }}" autofocus required placeholder="Booking code (SMB-…) or phone number" aria-label="Booking code or phone" class="input text-lg">
        <button class="btn btn-primary shrink-0 px-5">Find</button>
    </form>

    @if ($q !== '')
        <p class="mb-3 text-sm text-slate-500">{{ $results->count() }} {{ Str::plural('booking', $results->count()) }} for “{{ $q }}”{{ $results->count() === 30 ? ' (showing the first 30)' : '' }}</p>
        <div class="max-w-3xl space-y-4">
            @forelse ($results as $b)
                <div class="space-y-3">
                    @include('bookings._summary', ['b' => $b])
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        @include('bookings._actions', ['b' => $b])
                        <a href="{{ route('bookings.show', $b) }}" class="btn btn-secondary">Open booking</a>
                    </div>
                </div>
            @empty
                <div class="card p-8 text-center text-slate-500">No booking found. Check the code, or search by the phone number used for the enquiry.</div>
            @endforelse
        </div>
    @endif
</x-layouts.app>
