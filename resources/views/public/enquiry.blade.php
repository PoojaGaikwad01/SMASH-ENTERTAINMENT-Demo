<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Enquire · {{ $outlet->name }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-50 text-slate-800 antialiased">
<main class="mx-auto w-full max-w-lg px-4 py-6 sm:py-10">
    <div class="mb-5 flex items-center gap-3">
        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-orange-600 font-bold text-white">SE</span>
        <div>
            <h1 class="text-lg font-semibold leading-tight">Smash Entertainment</h1>
            <p class="text-sm text-slate-500">{{ $outlet->name }} · {{ $outlet->city }}</p>
        </div>
    </div>

    @if (session('submitted'))
        <div class="card space-y-3 p-6 text-center" role="status">
            <div class="text-4xl">🎉</div>
            <h2 class="text-lg font-semibold">Thank you!</h2>
            <p class="text-sm text-slate-600">We've received your enquiry. Our team at {{ $outlet->name }} will call or WhatsApp you shortly.</p>
            <a href="{{ route('public-enquiry.show', $outlet->slug) }}" class="btn btn-secondary">Send another enquiry</a>
        </div>
    @else
        <form method="POST" action="{{ route('public-enquiry.store', $outlet->slug) }}" x-data="{ sending: false }" @submit="sending = true" class="card space-y-4 p-5">
            @csrf
            <div>
                <h2 class="font-semibold">Birthday parties, events & tickets</h2>
                <p class="text-sm text-slate-500">Leave your details and we'll get back to you.</p>
            </div>
            <x-flash />

            {{-- Honeypot: hidden from people, bots fill it. --}}
            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Website</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>

            @include('enquiries._fields', ['notesLabel' => 'Anything else? (theme, timing, questions)'])

            <button class="btn btn-primary w-full py-3 text-base" :disabled="sending">Send enquiry</button>
            <p class="text-center text-xs text-slate-400">We only use your number to reply to this enquiry.</p>
        </form>
    @endif
</main>
</body>
</html>
