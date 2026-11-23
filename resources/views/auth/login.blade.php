<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center bg-slate-50 px-4 py-10 text-slate-800 antialiased">
<div class="w-full max-w-sm">
    <div class="mb-6 flex flex-col items-center gap-2 text-center">
        <span class="grid size-12 place-items-center rounded-xl bg-orange-600 text-lg font-bold text-white">SE</span>
        <h1 class="text-xl font-semibold">Smash Entertainment</h1>
        <p class="text-sm text-slate-500">Sign in to continue</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-6">
        @csrf
        <x-flash />
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
        </div>
        <div>
            <label for="password" class="label">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-orange-600"> Remember me
        </label>
        <button class="btn btn-primary w-full">Log in</button>
        <p class="text-center text-xs text-slate-500">Forgot your password? Ask your admin to reset it.</p>
    </form>
</div>
</body>
</html>
