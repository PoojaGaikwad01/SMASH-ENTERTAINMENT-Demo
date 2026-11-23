@props(['title' => null, 'flush' => false]) {{-- flush: full-height page without padding/heading (inbox) --}}
@php
    $user = auth()->user();
    $unread = $user->unreadNotifications()->count();
    $recentNotifications = $user->notifications()->latest()->limit(8)->get();
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
<div x-data="{ menu: false }" @keydown.escape.window="menu = false" class="min-h-full">
    {{-- Top bar --}}
    <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4">
        <button type="button" @click="menu = true" class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 md:hidden" aria-label="Open menu">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a href="{{ route('inbox') }}" class="flex items-center gap-2 font-semibold">
            <span class="grid size-8 place-items-center rounded-lg bg-orange-600 text-sm font-bold text-white">SE</span>
            <span class="hidden sm:inline">Smash <span class="font-normal text-slate-500">Entertainment</span></span>
        </a>
        @if (config('leads.mode') === 'mock')
            <span class="badge bg-amber-100 text-amber-800">Mock mode</span>
        @endif
        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            {{-- Notification bell (reminders). Polls the unread count every minute. --}}
            <div x-data="{ open: false, count: {{ $unread }} }" @click.outside="open = false" @keydown.escape.window="open = false"
                 x-init="setInterval(() => fetch(@js(route('notifications.count')), { headers: { Accept: 'application/json' } })
                     .then(r => r.ok ? r.json() : null).then(d => { if (d) count = d.count }).catch(() => {}), 60000)"
                 class="sm:relative">
                <button type="button" @click="open = !open" class="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100" aria-label="Notifications">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                    <span x-show="count > 0" x-cloak x-text="count > 9 ? '9+' : count"
                          class="absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold text-white"></span>
                </button>
                <div x-show="open" x-cloak x-transition.opacity
                     class="fixed inset-x-2 top-14 z-50 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-80">
                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                        <span class="text-sm font-semibold">Notifications</span>
                        @if ($unread)
                            <form method="POST" action="{{ route('notifications.read-all') }}">
                                @csrf
                                <button class="text-xs font-medium text-orange-700 hover:underline">Mark all read</button>
                            </form>
                        @endif
                    </div>
                    <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                        @forelse ($recentNotifications as $n)
                            <a href="{{ route('notifications.open', $n->id) }}" @class(['block px-4 py-3 text-sm hover:bg-slate-50', 'bg-orange-50' => ! $n->read_at])>
                                <div class="font-medium">{{ $n->data['title'] ?? 'Notification' }}</div>
                                <div class="text-xs text-slate-500">{{ $n->data['body'] ?? '' }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">{{ $n->created_at->diffForHumans() }}</div>
                            </a>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-slate-500">No notifications yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="max-w-36 text-right text-sm leading-tight">
                <div class="truncate font-medium">{{ $user->name }}</div>
                <div class="text-xs text-slate-500">{{ $user->role->label() }}@if ($user->branch) · {{ $user->branch->name }}@endif</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-secondary px-2.5 py-1.5" title="Log out">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                    <span class="hidden sm:inline">Logout</span>
                </button>
            </form>
        </div>
    </header>

    <div class="flex">
        {{-- Desktop sidebar --}}
        <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-56 shrink-0 border-r border-slate-200 bg-white p-3 md:block">
            <x-nav />
        </aside>

        {{-- Mobile drawer --}}
        <div x-show="menu" x-cloak class="fixed inset-0 z-40 md:hidden">
            <div class="absolute inset-0 bg-slate-900/40" @click="menu = false" x-transition.opacity></div>
            <aside class="absolute inset-y-0 left-0 w-64 bg-white p-3 shadow-xl" x-transition:enter="transition" x-transition:enter-start="-translate-x-full" x-transition:leave="transition" x-transition:leave-end="-translate-x-full">
                <div class="mb-3 flex items-center justify-between px-2">
                    <span class="font-semibold">Menu</span>
                    <button type="button" @click="menu = false" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Close menu">✕</button>
                </div>
                <x-nav />
            </aside>
        </div>

        <main @class(['min-w-0 flex-1', 'p-4 md:p-6' => ! $flush, 'h-[calc(100dvh-3.5rem)] overflow-hidden' => $flush])>
            @unless ($flush)
                @if ($title)
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h1 class="text-xl font-semibold">{{ $title }}</h1>
                        {{ $actions ?? '' }}
                    </div>
                @endif
                <x-flash />
            @endunless
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
