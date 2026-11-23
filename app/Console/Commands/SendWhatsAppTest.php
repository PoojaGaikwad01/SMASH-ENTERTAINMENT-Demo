<?php

namespace App\Console\Commands;

use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Live test tool: always calls the real WhatsApp Cloud API (ignores APP_MODE). */
class SendWhatsAppTest extends Command
{
    protected $signature = 'meta:send-test
        {to : Recipient WhatsApp number, e.g. 919876543210 or "+91 98765 43210"}
        {message? : Text to send (default: a short test message)}
        {--template= : Send an approved template instead (e.g. hello_world) — needed for a first message}
        {--lang=en_US : Template language code}';

    protected $description = 'Send a WhatsApp message via the Cloud API (WA_PHONE_NUMBER_ID + WA_ACCESS_TOKEN) and print the API response';

    public function handle(): int
    {
        $phoneNumberId = config('leads.whatsapp.phone_number_id');
        $token = config('leads.whatsapp.access_token');
        if (blank($phoneNumberId) || blank($token)) {
            $this->error('Set WA_PHONE_NUMBER_ID and WA_ACCESS_TOKEN in .env first (then run: php artisan config:clear).');

            return self::FAILURE;
        }

        $raw = (string) $this->argument('to');
        $to = ltrim(Phone::normalize($raw) ?? preg_replace('/\D/', '', $raw), '+');

        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to] + ($this->option('template')
            ? ['type' => 'template', 'template' => ['name' => $this->option('template'), 'language' => ['code' => $this->option('lang')]]]
            : ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $this->argument('message') ?? 'Test message from Smash Entertainment ✅']]);

        $url = 'https://graph.facebook.com/'.config('leads.meta.graph_version')."/{$phoneNumberId}/messages";
        $this->line("POST {$url}  (to: {$to}, type: {$payload['type']})");

        $response = Http::withToken($token)->acceptJson()->timeout(20)->post($url, $payload);

        $this->line('HTTP '.$response->status());
        $this->line(json_encode($response->json() ?? $response->body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($response->failed()) {
            $this->warn('Common causes: recipient not in the test number\'s allowed list; first message (or >24h since the customer wrote) must be a template, e.g. --template=hello_world; temporary access token expired (24h).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
