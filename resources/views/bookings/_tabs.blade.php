{{-- Bookings sub-navigation: List | Calendar (+ New booking hint). --}}
<nav class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" aria-label="Booking views">
    @foreach (['bookings.index' => 'List', 'bookings.calendar' => 'Calendar'] as $r => $label)
        <a href="{{ route($r) }}" @class(['rounded-md px-3 py-1.5 font-medium',
            'bg-white shadow-sm text-slate-900' => request()->routeIs($r), 'text-slate-600 hover:text-slate-900' => ! request()->routeIs($r)])>{{ $label }}</a>
    @endforeach
</nav>
