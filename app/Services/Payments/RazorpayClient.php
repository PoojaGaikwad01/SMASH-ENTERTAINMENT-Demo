<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Minimal Razorpay Payment Links API (no SDK). Basic auth with RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET.
 * APP_MODE=mock never calls Razorpay: it returns a fake plink_mock_… link.
 */
class RazorpayClient
{
    private const BASE = 'https://api.razorpay.com/v1/';

    public function isMock(): bool
    {
        return config('leads.mode') !== 'live';
    }

    /**
     * POST /payment_links. $data uses Razorpay's fields (amount in paise). Returns the API entity (id, short_url, status, …).
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function createPaymentLink(array $data): array
    {
        if ($this->isMock()) {
            $id = 'plink_mock_'.Str::lower(Str::random(14));
            Log::info('[mock] Razorpay payment link not created', ['reference_id' => $data['reference_id'] ?? null, 'amount' => $data['amount'] ?? null]);

            return ['id' => $id, 'short_url' => 'https://rzp.io/i/'.substr($id, 6), 'status' => 'created'] + $data;
        }

        return $this->http()->post('payment_links', $data)->throw()->json();
    }

    /** POST /payment_links/{id}/cancel — best effort (an already paid/expired link can't be cancelled). */
    public function cancelPaymentLink(string $id): void
    {
        if ($this->isMock()) {
            return;
        }

        $response = $this->http()->post("payment_links/{$id}/cancel");
        if ($response->failed()) {
            Log::warning('Razorpay payment link cancel failed', ['id' => $id, 'status' => $response->status()]);
        }
    }

    /** X-Razorpay-Signature = hex HMAC-SHA256 of the raw body with the webhook secret. */
    public function validWebhookSignature(string $body, ?string $signature): bool
    {
        $secret = (string) config('leads.payments.razorpay.webhook_secret');
        if ($secret === '') {
            return $this->isMock(); // mock mode may run without keys; live never does
        }

        return is_string($signature) && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    private function http()
    {
        return Http::baseUrl(self::BASE)
            ->withBasicAuth((string) config('leads.payments.razorpay.key_id'), (string) config('leads.payments.razorpay.key_secret'))
            ->acceptJson()->asJson()->timeout(20);
    }
}
