<?php

namespace App\Jobs;

use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Processing is idempotent, so retries are safe. */
class ProcessMetaWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public array $payload) {}

    public function handle(MetaWebhookProcessor $processor): void
    {
        $processor->process($this->payload);

        Log::channel('meta')->info('Webhook processed', ['object' => $this->payload['object'] ?? null]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('meta')->error('Webhook processing failed', ['object' => $this->payload['object'] ?? null, 'error' => $e->getMessage()]);
    }
}
