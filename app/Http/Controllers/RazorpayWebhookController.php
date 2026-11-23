<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Services\Payments\RazorpayClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/razorpay (routes/webhooks.php: no session/CSRF). Verifies X-Razorpay-Signature, then handles
 * payment_link.paid / .expired / .cancelled synchronously (idempotent). Always 200 once verified, so Razorpay doesn't retry
 * events we deliberately ignore.
 */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, RazorpayClient $razorpay, PaymentService $payments)
    {
        abort_unless($payments->enabled(), 404);

        if (! $razorpay->validWebhookSignature($request->getContent(), $request->header('X-Razorpay-Signature'))) {
            Log::warning('Razorpay webhook: invalid signature', ['ip' => $request->ip()]);
            abort(403, 'Invalid signature');
        }

        $outcome = $payments->handleWebhook($request->json()->all());
        Log::info('Razorpay webhook', ['event' => $request->json('event'), 'event_id' => $request->header('X-Razorpay-Event-Id'), 'outcome' => $outcome]);

        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }
}
