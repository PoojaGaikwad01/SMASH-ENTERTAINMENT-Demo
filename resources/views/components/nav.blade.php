@php
    $dueView = request()->routeIs('inbox*') && request('view') === 'due';
    $items = [
        ['route' => 'inbox', 'match' => 'inbox*', 'label' => 'Enquiries', 'admin' => false, 'active' => request()->routeIs('inbox*') && ! $dueView,
         'icon' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M2.25 13.5V18A2.25 2.25 0 0 0 4.5 20.25h15A2.25 2.25 0 0 0 21.75 18v-4.5M2.25 13.5l2.4-7.2A2.25 2.25 0 0 1 6.784 4.75h10.432a2.25 2.25 0 0 1 2.134 1.55l2.4 7.2'],
        ['route' => 'inbox', 'params' => ['view' => 'due'], 'label' => 'Follow-ups', 'admin' => false, 'active' => $dueView,
         'badge' => \App\Models\Lead::visibleTo(auth()->user())->followUpsDue()->count(),
         'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
        ['route' => 'bookings.index', 'label' => 'Bookings', 'admin' => false, 'active' => request()->routeIs('bookings.*') && ! request()->routeIs('bookings.lookup'),
         'icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5'],
        ['route' => 'bookings.lookup', 'match' => 'bookings.lookup', 'label' => 'Counter lookup', 'admin' => false,
         'icon' => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z'],
        ['route' => 'catalog.index', 'params' => ['type' => 'packages'], 'match' => 'catalog.*', 'label' => 'Catalogue', 'admin' => false,
         'visible' => auth()->user()->canManageCatalog(),
         'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z'],
        ['route' => 'branches.index', 'match' => 'branches.*', 'label' => 'Outlets', 'admin' => true,
         'icon' => 'M2.25 21h19.5M3.75 3v18m16.5-18v18M5.25 3h13.5M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21'],
        ['route' => 'users.index', 'match' => 'users.*', 'label' => 'Users', 'admin' => true,
         'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128H2.25v-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
        ['route' => 'reports', 'match' => 'reports*', 'label' => 'Reports', 'admin' => false,
         'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
    ];
@endphp
<nav class="space-y-1">
    @foreach ($items as $item)
        @continue(($item['admin'] && ! auth()->user()->isAdmin()) || ! ($item['visible'] ?? true))
        @php($active = $item['active'] ?? request()->routeIs($item['match']))
        <a href="{{ route($item['route'], $item['params'] ?? []) }}"
           @class(['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                   'bg-orange-50 text-orange-700' => $active,
                   'text-slate-600 hover:bg-slate-100' => ! $active])>
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
            {{ $item['label'] }}
            @if (! empty($item['badge']))<span class="ml-auto rounded-full bg-rose-600 px-2 text-xs font-semibold text-white">{{ $item['badge'] }}</span>@endif
        </a>
    @endforeach
</nav>
