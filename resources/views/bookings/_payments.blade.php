{{-- Advance payment via Razorpay Payment Links (PAYMENTS_ENABLED). PaymentLinkController; webhook credits the booking. --}}
@php
    use App\Support\Format;
    $mock = config('leads.mode') !== 'live';
    $canUpdate = auth()->user()->can('update', $b);
    $canCreate = $canUpdate && $b->status->holdsSlot() && (float) $b->balance_due > 0;
    $open = $b->openPaymentLink();
@endphp
<div class="card">
    <div class="border-b border-slate-100 p-4">
        <h2 class="font-semibold">Advance payment <span class="text-xs font-normal text-slate-400">· Razorpay</span></h2>
        <p class="text-xs text-slate-500">
            Paid so far {{ Format::money($b->advance_paid) }} · balance {{ Format::money($b->balance_due) }}.
            Paying a link confirms a tentative booking automatically.
            @if ($mock)<span class="font-medium text-sky-700">Mock mode: no real link, use “Mark as paid (mock)”.</span>@endif
        </p>
    </div>

    @if ($canCreate)
        <form method="POST" action="{{ route('payment-links.store', $b) }}" class="flex flex-wrap items-end gap-2 border-b border-slate-100 p-4">
            @csrf
            <label class="block">
                <span class="label">Amount (₹)</span>
                <input type="number" name="amount" value="{{ old('amount', $b->suggestedAdvance()) }}" min="1" max="{{ (float) $b->balance_due }}" step="0.01" required class="input w-40">
            </label>
            <button class="btn btn-primary">{{ $open ? 'Create new link & send' : 'Create link & send' }}</button>
            <p class="w-full text-xs text-slate-500">
                Sent on WhatsApp + email. Suggested: {{ config('leads.payments.advance_percent') }}% of the total.
                Expires in {{ config('leads.payments.link_expiry_hours') }} h.@if ($open) Creating a new link cancels the open one.@endif
            </p>
        </form>
    @endif

    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Amount</th><th>Status</th><th>Link</th><th>Dates</th><th></th></tr></thead>
            <tbody>
                @forelse ($b->paymentLinks as $l)
                    <tr>
                        <td class="whitespace-nowrap font-medium">{{ Format::money($l->amount) }}</td>
                        <td><span class="badge {{ $l->statusColor() }}">{{ ucfirst($l->displayStatus()) }}</span></td>
                        <td class="max-w-56 truncate text-xs">
                            <a href="{{ $l->short_url }}" target="_blank" rel="noopener" class="text-orange-700 hover:underline">{{ $l->short_url }}</a>
                            <div class="text-slate-400">{{ $l->provider_payment_id ?? $l->reference_id }}</div>
                        </td>
                        <td class="text-xs whitespace-nowrap text-slate-500">
                            Created {{ $l->created_at->format('d M, g:i a') }}
                            @if ($l->paid_at)<div class="text-emerald-700">Paid {{ $l->paid_at->format('d M, g:i a') }}</div>
                            @elseif ($l->expires_at)<div>Expires {{ $l->expires_at->format('d M, g:i a') }}</div>@endif
                        </td>
                        <td class="text-right">
                            @if ($mock && $canUpdate && $l->isOpen())
                                <form method="POST" action="{{ route('payment-links.mock-paid', $l) }}">
                                    @csrf
                                    <button class="btn btn-secondary py-1 text-xs">Mark as paid (mock)</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-sm text-slate-500">No payment links yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
