<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\PaymentLink;
use App\Services\PaymentService;
use App\Support\Format;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Razorpay advance links (PAYMENTS_ENABLED, else 404). BookingPolicy::update. */
class PaymentLinkController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function store(Request $request, Booking $booking)
    {
        abort_unless($this->payments->enabled(), 404);
        Gate::authorize('update', $booking);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:9999999']]);

        try {
            $link = $this->payments->createLink($booking, (float) $data['amount'], $request->user());
        } catch (RequestException $e) {
            Log::warning('Razorpay payment link failed', ['booking' => $booking->booking_code, 'status' => $e->response->status()]);

            return back()->withErrors(['amount' => 'Razorpay could not create the link: '.($e->response->json('error.description') ?? 'HTTP '.$e->response->status())]);
        }

        return back()->with('status', 'Payment link for '.Format::money($link->amount).' created and sent on WhatsApp + email.');
    }

    /** APP_MODE=mock only: simulate the customer paying — same code path as the Razorpay webhook. */
    public function mockPaid(Request $request, PaymentLink $link)
    {
        abort_unless($this->payments->enabled() && config('leads.mode') !== 'live', 404);
        Gate::authorize('update', $link->booking);

        $credited = $this->payments->markPaid($link, 'pay_mock_'.Str::lower(Str::random(14)), null, $request->user());

        return back()->with('status', $credited
            ? 'Marked as paid (mock): '.Format::money($link->amount).' added to the advance.'
            : 'This link was already paid.');
    }
}
