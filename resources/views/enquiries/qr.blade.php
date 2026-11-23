<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR poster · {{ $outlet->name }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 15mm; }
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        .qr svg { width: 100%; height: auto; }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
<div class="no-print mx-auto flex max-w-xl items-center justify-between gap-2 px-4 pt-4">
    <a href="{{ url()->previous() }}" class="btn btn-secondary">‹ Back</a>
    <button type="button" onclick="window.print()" class="btn btn-primary">🖨 Print</button>
</div>

<main class="mx-auto my-4 max-w-xl bg-white px-10 py-12 text-center shadow-sm print:my-0 print:shadow-none">
    <p class="text-sm font-semibold uppercase tracking-widest text-orange-600">Smash Entertainment · {{ $outlet->name }}</p>
    <h1 class="mt-3 text-4xl font-bold leading-tight">Planning a party or event?</h1>
    <p class="mt-3 text-lg text-slate-600">Scan to send us an enquiry — birthday parties, school & corporate groups, tickets.</p>

    <div class="qr mx-auto mt-8 w-72 rounded-2xl border-4 border-orange-600 p-4">{!! $svg !!}</div>

    <p class="mt-6 text-sm text-slate-500">or open</p>
    <p class="break-all font-mono text-base font-medium">{{ $url }}</p>
    <p class="mt-8 text-base text-slate-600">Our team will call or WhatsApp you shortly. 🎉</p>
</main>
</body>
</html>
