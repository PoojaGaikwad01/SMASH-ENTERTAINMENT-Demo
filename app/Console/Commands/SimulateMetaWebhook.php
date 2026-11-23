<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Tests the full HTTP path: signature check → 200 → queued job. */
class SimulateMetaWebhook extends Command
{
    protected $signature = 'meta:simulate-webhook
        {fixture : Fixture in tests/fixtures/meta without .json, e.g. whatsapp_text}
        {--url= : Endpoint (default: APP_URL/webhooks/meta), e.g. your cloudflared URL}';

    protected $description = 'POST a Meta fixture to the webhook endpoint with a valid X-Hub-Signature-256 header';

    public function handle(): int
    {
        $dir = base_path('tests/fixtures/meta');
        $file = $dir.'/'.basename((string) $this->argument('fixture'), '.json').'.json';
        if (! is_file($file)) {
            $this->error('Fixture not found. Available: '.implode(', ', array_map(fn ($f) => basename($f, '.json'), glob("{$dir}/*.json"))));

            return self::FAILURE;
        }

        // Sign exactly the bytes we send — that's what the endpoint verifies.
        $body = json_encode(ReplayMetaFixtures::load($file), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret = (string) config('leads.meta.app_secret');
        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/meta';

        $headers = [];
        if ($secret !== '') {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        } else {
            $this->warn('META_APP_SECRET is empty: sending unsigned (accepted only when APP_MODE=mock).');
        }

        $response = Http::withHeaders($headers)->withBody($body, 'application/json')->timeout(30)->post($url);
        $this->line("POST {$url} -> HTTP {$response->status()} {$response->body()}");

        if (! $response->successful()) {
            return self::FAILURE;
        }
        $this->info('Accepted and queued. Make sure "php artisan queue:work" is running, then check the inbox and storage/logs/meta.log.');

        return self::SUCCESS;
    }
}
