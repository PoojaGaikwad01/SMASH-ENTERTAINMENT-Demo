<?php

namespace App\Services\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Thin Graph API client. In APP_MODE=mock it never calls Meta:
 * Graph objects come from tests/fixtures/meta/graph/{id}.json, media from tests/fixtures/meta/media/{filename}.
 */
class MetaClient
{
    public function isMock(): bool
    {
        return config('leads.mode') !== 'live';
    }

    /** GET a Graph object by id. */
    public function get(string $id, array $query = [], ?string $token = null): array
    {
        if ($this->isMock()) {
            $file = base_path('tests/fixtures/meta/graph/'.basename($id).'.json');

            return is_file($file) ? json_decode(file_get_contents($file), true) : [];
        }

        return Http::baseUrl('https://graph.facebook.com/'.config('leads.meta.graph_version'))
            ->withToken($token ?? config('leads.meta.page_access_token'))
            ->timeout(15)
            ->retry(2, 300)
            ->get($id, $query)
            ->json() ?? [];
    }

    public function getLead(string $leadgenId): array
    {
        return $this->get($leadgenId, ['fields' => 'id,created_time,field_data,ad_id,ad_name,adset_name,campaign_name,form_id,platform']);
    }

    public function getFormName(?string $formId): ?string
    {
        if (! $formId) {
            return null;
        }

        return Cache::remember("meta.form.{$formId}", now()->addDay(), fn () => $this->get($formId, ['fields' => 'name'])['name'] ?? null);
    }

    /** Messenger / Instagram sender's display name. Never throws (profile access may be restricted). */
    public function getProfileName(string $userId, string $channel): ?string
    {
        try {
            $d = $this->get($userId, ['fields' => $channel === 'instagram' ? 'name,username' : 'first_name,last_name']);
        } catch (\Throwable $e) {
            Log::warning('Meta profile lookup failed', ['id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        return ($d['name'] ?? null) ?: (trim(($d['first_name'] ?? '').' '.($d['last_name'] ?? '')) ?: ($d['username'] ?? null));
    }

    /**
     * Send a free-text reply. Returns the platform message id. Throws on API errors.
     * whatsapp → Cloud API /{WA_PHONE_NUMBER_ID}/messages (recipient = phone digits)
     * messenger / instagram → /me/messages with the Page token (recipient = PSID / IG-scoped id)
     */
    public function sendText(string $channel, string $recipient, string $text): string
    {
        if ($this->isMock()) {
            return 'mock.out.'.Str::uuid();
        }

        $http = Http::baseUrl('https://graph.facebook.com/'.config('leads.meta.graph_version'))->timeout(20)->acceptJson();

        $response = match ($channel) {
            'whatsapp' => $http->withToken(config('leads.whatsapp.access_token'))
                ->post(config('leads.whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $text],
                ]),
            'messenger', 'instagram' => $http->withToken(config('leads.meta.page_access_token'))
                ->post('me/messages', [
                    'recipient' => ['id' => $recipient],
                    'messaging_type' => 'RESPONSE',
                    'message' => ['text' => $text],
                ]),
            default => throw new \InvalidArgumentException("Cannot send on channel '{$channel}'"),
        };

        $response->throw();

        return $response->json('messages.0.id') ?? $response->json('message_id') ?? throw new \RuntimeException('No message id in API response');
    }

    /**
     * Send an approved WhatsApp template (works outside the 24h window). $params fill the body {{1}}, {{2}}, …
     * Retries twice on connection / 5xx errors; throws on any other API error. Returns the wamid.
     */
    public function sendTemplate(string $to, string $template, string $lang, array $params = []): string
    {
        if ($this->isMock()) {
            return 'mock.out.'.Str::uuid();
        }

        $retryable = fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError());
        $response = Http::baseUrl('https://graph.facebook.com/'.config('leads.meta.graph_version'))
            ->withToken(config('leads.whatsapp.access_token'))
            ->timeout(20)->acceptJson()
            ->retry(3, 500, $retryable, throw: false)
            ->post(config('leads.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => array_filter([
                    'name' => $template,
                    'language' => ['code' => $lang],
                    'components' => $params ? [['type' => 'body', 'parameters' => array_map(
                        fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params))]] : null,
                ]),
            ]);

        $response->throw();

        return $response->json('messages.0.id') ?? throw new \RuntimeException('No message id in API response');
    }

    /**
     * WhatsApp media: GET /{media_id} -> url -> download (WA token). Stores on the private disk.
     * Returns the storage path, e.g. "media/whatsapp/{media_id}/leads.xlsx".
     */
    public function downloadWhatsAppMedia(string $mediaId, ?string $filename = null, ?string $mime = null): string
    {
        $filename = Str::of($filename ?: 'file.'.Str::afterLast((string) $mime, '/'))->basename()->replaceMatches('/[^\w.\- ]/', '_')->toString();
        $path = 'media/whatsapp/'.Str::slug($mediaId, '_').'/'.$filename;

        if ($this->isMock()) {
            $fixture = base_path('tests/fixtures/meta/media/'.$filename);
            Storage::put($path, is_file($fixture) ? file_get_contents($fixture) : "Mock media {$mediaId}");

            return $path;
        }

        $token = config('leads.whatsapp.access_token');
        $url = $this->get($mediaId, [], $token)['url'] ?? throw new \RuntimeException("No URL for WhatsApp media {$mediaId}");
        Storage::put($path, Http::withToken($token)->timeout(60)->retry(2, 500)->get($url)->body());

        return $path;
    }
}
