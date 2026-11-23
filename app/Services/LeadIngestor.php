<?php

namespace App\Services;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Message;
use App\Support\Phone;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single place where inbound data becomes leads/messages.
 * Idempotent (external_id / external_message_id) and dedupes by phone (or chat sender) within DEDUPE_DAYS.
 */
class LeadIngestor
{
    private const FILLABLE_BLANKS = ['name', 'phone', 'email', 'city', 'contact_id', 'campaign_name', 'ad_name', 'form_name', 'agency_name', 'preferred_date', 'guest_count'];

    /** Also true when an agency message was split into leads ("{id}#1", "{id}#2", …). */
    public function alreadyProcessed(string $externalMessageId): bool
    {
        return Message::whereIn('external_message_id', [$externalMessageId, $externalMessageId.'#1'])->exists();
    }

    public function leadAdExists(string $leadgenId): bool
    {
        return Lead::whereIn('source', [LeadSource::FbLeadAd, LeadSource::IgLeadAd])->where('external_id', $leadgenId)->exists()
            || $this->alreadyProcessed("leadgen:{$leadgenId}");
    }

    /** Most recent lead active within DEDUPE_DAYS with the same phone, or the same chat sender on the same source. */
    public function findDuplicate(?string $phone, ?LeadSource $source = null, ?string $contactId = null): ?Lead
    {
        $phone = Phone::normalize($phone);
        $byContact = $contactId && $source;
        if (! $phone && ! $byContact) {
            return null;
        }

        return Lead::where('last_activity_at', '>=', now()->subDays(config('leads.dedupe_days')))
            ->where(function ($q) use ($phone, $byContact, $source, $contactId) {
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
                if ($byContact) {
                    $q->orWhere(fn ($q) => $q->where('source', $source)->where('contact_id', $contactId));
                }
            })
            ->latest('last_activity_at')->latest('id')
            ->first();
    }

    /**
     * Inbound chat message (Messenger / Instagram / WhatsApp customer).
     * $message: external_message_id, body, attachment_url, created_at. $lead: contact_id, phone, name.
     * $resolveName is only called when a new lead is created (avoids a profile API call per message).
     */
    public function ingestChat(LeadSource $source, array $message, array $lead, ?Closure $resolveName = null): ?Lead
    {
        if ($this->alreadyProcessed($message['external_message_id'])) {
            return null;
        }

        $existing = $this->findDuplicate($lead['phone'] ?? null, $source, $lead['contact_id'] ?? null);
        if (! $existing && $resolveName && blank($lead['name'] ?? null)) {
            $lead['name'] = $resolveName();
        }

        return $this->store(
            $existing,
            $lead + ['source' => $source, 'external_id' => $message['external_message_id']],
            $message + ['channel' => $source->channel()],
        );
    }

    /** Lead Ad submission. A duplicate phone gets the form summary as a message instead of a new lead. */
    public function ingestLeadAd(LeadSource $source, string $leadgenId, array $lead, string $summary): ?Lead
    {
        if ($this->leadAdExists($leadgenId)) {
            return null;
        }

        $existing = $this->findDuplicate($lead['phone'] ?? null);

        return $this->store(
            $existing,
            $lead + ['source' => $source, 'external_id' => $leadgenId],
            $existing ? ['external_message_id' => "leadgen:{$leadgenId}", 'channel' => 'lead_ad', 'body' => $summary] : null,
        );
    }

    /**
     * Create the lead (or update the duplicate) and its inbound message atomically.
     * Returns null if another worker processed the same event concurrently.
     */
    public function store(?Lead $existing, array $lead, ?array $message): ?Lead
    {
        try {
            return DB::transaction(function () use ($existing, $lead, $message) {
                if ($existing) {
                    // Only fill blanks — never overwrite what an admin may have corrected.
                    foreach (self::FILLABLE_BLANKS as $key) {
                        if (blank($existing->{$key}) && filled($lead[$key] ?? null)) {
                            $existing->{$key} = $lead[$key];
                        }
                    }
                    if ($existing->status === LeadStatus::Lost) {
                        $existing->status = LeadStatus::New;
                    }
                    $existing->save();
                    $model = $existing;
                } else {
                    $model = Lead::create($lead + ['status' => LeadStatus::New]);
                }

                if ($message) {
                    $model->messages()->create($message + ['direction' => 'in']);
                }

                return $model;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
