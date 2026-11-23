<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** One endpoint for Page (leadgen + Messenger), Instagram and WhatsApp webhooks. */
class MetaWebhookController extends Controller
{
    /** Subscription handshake. PHP turns "hub.mode" into "hub_mode". */
    public function verify(Request $request)
    {
        $token = (string) config('leads.meta.verify_token');

        if (! ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token')))) {
            Log::channel('meta')->warning('Webhook verification failed', ['mode' => $request->query('hub_mode'), 'token_configured' => $token !== '']);
            abort(403);
        }

        Log::channel('meta')->info('Webhook verified');

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    /** Verify signature, log ids, queue, and answer 200 immediately. */
    public function receive(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            Log::channel('meta')->warning('Webhook rejected: invalid X-Hub-Signature-256', ['secret_configured' => filled(config('leads.meta.app_secret'))]);
            abort(403, 'Invalid signature');
        }

        if ($payload = $request->json()->all()) {
            Log::channel('meta')->info('Webhook received', $this->summary($payload));
            ProcessMetaWebhook::dispatch($payload);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('leads.meta.app_secret');

        if ($secret === '') {
            return config('leads.mode') !== 'live'; // mock mode may run without keys; live never does
        }

        // Must be computed over the RAW body exactly as Meta sent it.
        return hash_equals(
            'sha256='.hash_hmac('sha256', $request->getContent(), $secret),
            (string) $request->header('X-Hub-Signature-256')
        );
    }

    /** Object type + message ids only — never names, phone numbers or message text. */
    private function summary(array $payload): array
    {
        $ids = [];
        $statuses = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $m) {
                    $ids[] = $m['id'] ?? null;
                }
                $statuses += count($value['statuses'] ?? []);
                if (isset($value['leadgen_id'])) {
                    $ids[] = 'leadgen:'.$value['leadgen_id'];
                }
            }
            foreach ($entry['messaging'] ?? [] as $event) {
                $ids[] = $event['message']['mid'] ?? null;
            }
        }

        return ['object' => $payload['object'] ?? null, 'message_ids' => array_values(array_filter($ids)), 'statuses' => $statuses];
    }
}
