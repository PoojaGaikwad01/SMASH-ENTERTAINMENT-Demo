<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Enquiries from our own channels: staff form (phone call / walk-in), public QR walk-in form, website API.
 * Meta channels go through LeadIngestor instead; both end up in the same `leads` (= enquiries) table.
 */
class EnquiryIntake
{
    /** Validation rules shared by all three channels. */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', function ($attribute, $value, $fail) {
                if (! is_scalar($value) || ! Phone::normalize((string) $value)) {
                    $fail('Enter a valid mobile number (10 digits, or with +country code).');
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'enquiry_type' => ['nullable', Rule::enum(EnquiryType::class)],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Create an enquiry, or merge into the customer's open enquiry (same phone; same outlet or no outlet yet).
     *
     * @return array{0: Lead, 1: bool} [enquiry, merged into an existing one]
     */
    public function submit(array $data, LeadSource $source, ?Branch $outlet, ?User $by = null): array
    {
        $type = EnquiryType::tryFrom((string) ($data['enquiry_type'] ?? '')) ?? EnquiryType::General;
        $fields = [
            'name' => trim($data['name']),
            'phone' => Phone::normalize((string) $data['phone']),
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'enquiry_type' => $type,
            'preferred_date' => $data['preferred_date'] ?? null,
            'guest_count' => filled($data['guest_count'] ?? null) ? (int) $data['guest_count'] : null,
        ];
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $followUp = now()->addHours(config('leads.follow_up_default_hours'));

        return DB::transaction(function () use ($fields, $notes, $source, $outlet, $by, $type, $followUp) {
            if ($existing = $this->findOpenDuplicate($fields['phone'], $outlet?->id)) {
                foreach (['name', 'email', 'preferred_date', 'guest_count'] as $key) {
                    if (blank($existing->{$key}) && filled($fields[$key])) {
                        $existing->{$key} = $fields[$key];
                    }
                }
                if ($existing->enquiry_type === EnquiryType::General) {
                    $existing->enquiry_type = $type;
                }
                if (! $existing->branch_id && $outlet) {
                    $existing->forceFill(['branch_id' => $outlet->id, 'assigned_at' => now(), 'assigned_by' => $by?->id]);
                }
                $existing->next_follow_up_at ??= $followUp;
                $existing->save();

                $existing->logActivity(ActivityType::Note, $this->repeatNote($source, $fields, $notes), $by);

                return [$existing, true];
            }

            $lead = Lead::create($fields + [
                'source' => $source,
                'status' => LeadStatus::New,
                'notes' => $notes,
                'branch_id' => $outlet?->id,
                'assigned_by' => $outlet ? $by?->id : null,
                'assigned_at' => $outlet ? now() : null,
                'created_by_user_id' => $by?->id,
                'next_follow_up_at' => $followUp,
            ]);

            return [$lead, false];
        });
    }

    /** Most recent open enquiry with this phone. With an outlet: only that outlet's or unassigned ones. */
    public function findOpenDuplicate(?string $phone, ?int $outletId): ?Lead
    {
        if (! $phone) {
            return null;
        }

        return Lead::where('phone', $phone)
            ->whereIn('status', LeadStatus::openValues())
            ->when($outletId, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $outletId)))
            ->latest('last_activity_at')->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function repeatNote(LeadSource $source, array $f, ?string $notes): string
    {
        $parts = array_filter([
            $f['enquiry_type']->label(),
            $f['preferred_date'] ? \Illuminate\Support\Carbon::parse($f['preferred_date'])->format('d M Y') : null,
            $f['guest_count'] ? $f['guest_count'].' guests' : null,
        ]);

        return 'Repeat enquiry via '.$source->label().': '.implode(' · ', $parts).($notes ? " — {$notes}" : '');
    }
}
