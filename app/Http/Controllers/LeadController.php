<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\EnquiryType;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Services\Meta\MetaClient;
use App\Services\ReminderService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /** Correct enquiry details (admin or the enquiry's outlet staff). Saving a needs-review lead with a valid phone clears the flag. */
    public function update(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
                if (filled($value) && ! Phone::normalize($value)) {
                    $fail('Enter a valid mobile number (10 digits, or with +country code).');
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'agency_name' => ['nullable', 'string', 'max:255'],
            'enquiry_type' => ['sometimes', Rule::enum(EnquiryType::class)],
            'preferred_date' => ['nullable', 'date'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead->fill($data);

        $reviewed = $lead->status === LeadStatus::NeedsReview && $lead->phone;
        if ($reviewed) {
            $lead->status = LeadStatus::New;
        }
        $lead->save();

        return back()->with('status', match (true) {
            (bool) $reviewed => 'Saved and marked as reviewed.',
            $lead->status === LeadStatus::NeedsReview => 'Saved. Still needs review — add a phone number.',
            default => 'Enquiry saved.',
        });
    }

    /** Admin: assign or reassign one lead. */
    public function assign(Request $request, Lead $lead)
    {
        $branch = $this->activeBranch($request);

        if ($lead->status === LeadStatus::NeedsReview) {
            return back()->withErrors(['branch_id' => 'Review and fix this enquiry before assigning it.']);
        }

        $lead->assignTo($branch, $request->user());

        return back()->with('status', "Assigned to {$branch->name}.");
    }

    /** Admin: assign many leads at once. Needs-review leads are skipped. */
    public function bulkAssign(Request $request)
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer']])['ids'];
        $branch = $this->activeBranch($request);

        $leads = Lead::whereIn('id', $ids)->get();
        [$review, $assignable] = $leads->partition(fn (Lead $l) => $l->status === LeadStatus::NeedsReview);
        $assignable->each(fn (Lead $l) => $l->assignTo($branch, $request->user()));

        return back()->with('status', "{$assignable->count()} enquiry(s) assigned to {$branch->name}."
            .($review->isNotEmpty() ? " {$review->count()} skipped (need review)." : ''));
    }

    /** Free-text reply on the customer's channel — only inside the 24h window. Mock mode stores without calling Meta. */
    public function reply(Request $request, Lead $lead, MetaClient $meta)
    {
        Gate::authorize('reply', $lead);
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];

        $window = $lead->replyWindow();
        if (! $window['open']) {
            return back()->withInput($this->draft($body))->withErrors(['body' => '24h window closed — free-text replies are disabled. Call or WhatsApp the customer, then use "Mark reply sent".']);
        }

        try {
            $externalId = $meta->sendText($window['channel'], $window['recipient'], $body);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput($this->draft($body))->withErrors(['body' => 'Could not send: '.Str::limit($e->getMessage(), 160)]);
        }

        $lead->messages()->create([
            'direction' => 'out',
            'channel' => $window['channel'],
            'body' => $body,
            'external_message_id' => $externalId,
            'sent_by_user_id' => $request->user()->id,
        ]);
        $lead->markReplied();

        return back()->with('status', 'Reply sent.');
    }

    /** Replied outside the app (call / personal WhatsApp) — logged on the timeline. Marking lost: EnquiryPipelineController::status. */
    public function markReplied(Request $request, Lead $lead)
    {
        Gate::authorize('reply', $lead);

        $lead->logActivity(ActivityType::Message, 'Replied outside the app (call / personal WhatsApp)');
        $lead->markReplied();

        return back()->with('status', 'Marked as contacted.');
    }

    /** Admin: nudge the outlet staff about a new enquiry nobody has responded to yet. */
    public function remind(Lead $lead, ReminderService $reminders)
    {
        if (! $lead->awaitingReply()) {
            return back()->withErrors(['lead' => 'Reminders are only for new enquiries at an outlet that are still waiting for a reply.']);
        }

        $sent = $reminders->remind($lead);

        return $sent
            ? back()->with('status', "Reminder sent to {$sent} staff member(s).")
            : back()->withErrors(['lead' => 'This outlet has no active staff to remind.']);
    }

    /** Keep the typed reply after an error only if it's short — cookie sessions are capped at ~4 KB. */
    private function draft(string $body): array
    {
        return mb_strlen($body) <= 500 ? ['body' => $body] : [];
    }

    private function activeBranch(Request $request): Branch
    {
        $id = $request->validate(['branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)]])['branch_id'];

        return Branch::findOrFail($id);
    }
}
