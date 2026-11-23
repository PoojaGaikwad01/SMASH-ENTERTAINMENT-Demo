<?php

namespace App\Services\Meta;

use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Services\Agency\AgencyParser;
use App\Services\LeadIngestor;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Routes one Meta webhook payload (page / instagram / whatsapp_business_account) to the ingestor. */
class MetaWebhookProcessor
{
    public function __construct(
        private MetaClient $meta,
        private LeadIngestor $ingestor,
        private AgencyParser $agency,
    ) {}

    public function process(array $payload): void
    {
        $object = $payload['object'] ?? null;

        foreach ($payload['entry'] ?? [] as $entry) {
            match ($object) {
                'page' => $this->page($entry),
                'instagram' => $this->messaging($entry, LeadSource::IgDm),
                'whatsapp_business_account' => $this->whatsapp($entry),
                default => Log::channel('meta')->info('Webhook ignored: unhandled object', ['object' => $object]),
            };
        }
    }

    private function page(array $entry): void
    {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? null) === 'leadgen') {
                $this->leadgen($change['value'] ?? []);
            }
        }

        $this->messaging($entry, LeadSource::Messenger);
    }

    /** Lead Ads: the webhook only carries leadgen_id; fetch the lead + form name from the Graph API. */
    private function leadgen(array $value): void
    {
        $id = (string) ($value['leadgen_id'] ?? '');
        if ($id === '' || $this->ingestor->leadAdExists($id)) {
            return;
        }

        $data = $this->meta->getLead($id);
        $source = ($data['platform'] ?? null) === 'ig' ? LeadSource::IgLeadAd : LeadSource::FbLeadAd;
        $fields = collect($data['field_data'] ?? [])
            ->mapWithKeys(fn ($f) => [strtolower($f['name'] ?? '') => implode(', ', (array) ($f['values'] ?? []))]);
        $pick = fn (string ...$keys) => collect($keys)->map(fn ($k) => $fields[$k] ?? null)->first(fn ($v) => filled($v));
        $formName = $this->meta->getFormName($data['form_id'] ?? $value['form_id'] ?? null);

        $summary = "📝 Submitted {$source->label()} form".($formName ? " “{$formName}”" : '')."\n"
            .$fields->map(fn ($v, $k) => ucfirst(str_replace('_', ' ', $k)).": {$v}")->join("\n");

        $this->ingestor->ingestLeadAd($source, $id, [
            'name' => $pick('full_name', 'name') ?? (trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? '')) ?: null),
            'phone' => $pick('phone_number', 'phone', 'mobile_number', 'mobile', 'whatsapp_number'),
            'email' => $pick('email'),
            'city' => $pick('city', 'location', 'area'),
            'enquiry_type' => EnquiryType::guess($pick('enquiry_type', 'event_type', 'occasion', 'interested_in'))
                ?? EnquiryType::guess($formName) ?? EnquiryType::General,
            'preferred_date' => $this->leadDate($pick('preferred_date', 'event_date', 'date')),
            'guest_count' => $this->leadCount($pick('number_of_guests', 'guest_count', 'guests', 'no_of_guests', 'number_of_people', 'number_of_kids')),
            'campaign_name' => $data['campaign_name'] ?? null,
            'ad_name' => $data['ad_name'] ?? null,
            'form_name' => $formName,
            'raw_payload' => $data + ['webhook' => $value],
        ], $summary);
    }

    /** Messenger (object=page) and Instagram DMs (object=instagram) share the same "messaging" shape. */
    private function messaging(array $entry, LeadSource $source): void
    {
        foreach ($entry['messaging'] ?? [] as $event) {
            $msg = $event['message'] ?? null;
            $senderId = (string) ($event['sender']['id'] ?? '');

            // Skip delivery/read receipts, echoes of our own messages, and anything without an id.
            if (! $msg || empty($msg['mid']) || $senderId === '' || ($msg['is_echo'] ?? false) || $senderId === (string) ($entry['id'] ?? '')) {
                continue;
            }

            $attachment = $msg['attachments'][0]['payload']['url'] ?? null;

            $this->ingestor->ingestChat($source, [
                'external_message_id' => $msg['mid'],
                'body' => $msg['text'] ?? ($attachment ? null : '[unsupported message]'),
                'attachment_url' => $attachment,
                'created_at' => $this->time($event['timestamp'] ?? null, ms: true),
            ], ['contact_id' => $senderId], fn () => $this->meta->getProfileName($senderId, $source->channel()));
        }
    }

    private function whatsapp(array $entry): void
    {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? null) !== 'messages') {
                continue; // e.g. template / account updates
            }
            $value = $change['value'] ?? [];
            $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);

            // value.statuses (sent/delivered/read) are ignored.
            foreach ($value['messages'] ?? [] as $m) {
                if (empty($m['id']) || empty($m['from']) || $this->ingestor->alreadyProcessed($m['id'])) {
                    continue;
                }
                $this->whatsappMessage($m, $names[$m['from']] ?? null);
            }
        }
    }

    private function whatsappMessage(array $m, ?string $profileName): void
    {
        $type = $m['type'] ?? 'unknown';
        $media = is_array($m[$type] ?? null) ? $m[$type] : [];
        $attachment = in_array($type, ['document', 'image', 'video', 'audio'], true) && ! empty($media['id'])
            ? $this->meta->downloadWhatsAppMedia($media['id'], $media['filename'] ?? null, $media['mime_type'] ?? null)
            : null;
        $body = match ($type) {
            'text' => $m['text']['body'] ?? '',
            'document', 'image', 'video', 'audio' => $media['caption'] ?? null,
            'button' => $m['button']['text'] ?? null,
            'interactive' => $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? null,
            default => "[{$type} message]",
        };
        $from = (string) $m['from'];
        $at = $this->time($m['timestamp'] ?? null);

        if ($this->isAgency($from)) {
            $this->agency->handle([
                'id' => $m['id'],
                'from' => $from,
                'agency_name' => $profileName ?: '+'.$from,
                'text' => $body,
                'attachment_url' => $attachment,
                'filename' => $media['filename'] ?? null,
                'mime' => $media['mime_type'] ?? null,
                'created_at' => $at,
            ]);

            return;
        }

        $this->ingestor->ingestChat(LeadSource::WhatsappCustomer, [
            'external_message_id' => $m['id'],
            'body' => $body,
            'attachment_url' => $attachment,
            'created_at' => $at,
        ], ['contact_id' => $from, 'phone' => '+'.$from, 'name' => $profileName]);
    }

    /** Lead-form date answer -> Y-m-d. Accepts "14/12/2026", "14-12-26", "14 Dec 2026", ISO… Null if unparseable. */
    private function leadDate(?string $v): ?string
    {
        if (blank($v)) {
            return null;
        }
        try {
            if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})$#', trim($v), $m)) {
                return Carbon::createFromFormat(strlen($m[3]) === 2 ? 'd/m/y' : 'd/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}")->toDateString();
            }

            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** First number in a lead-form answer ("20 kids", "approx 35") -> int. */
    private function leadCount(?string $v): ?int
    {
        return preg_match('/\d+/', (string) $v, $m) ? min((int) $m[0], 65535) : null;
    }

    private function isAgency(string $waId): bool
    {
        $from = Phone::normalize('+'.$waId);

        return $from !== null && config('leads.agency_parsing') && collect(config('leads.agency_numbers'))->contains(fn ($n) => Phone::normalize($n) === $from);
    }

    private function time(mixed $ts, bool $ms = false): CarbonInterface
    {
        return is_numeric($ts)
            ? Carbon::createFromTimestamp($ms ? $ts / 1000 : (int) $ts, config('app.timezone'))
            : now();
    }
}
