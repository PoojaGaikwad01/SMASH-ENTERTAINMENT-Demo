<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Enquiry detail actions: status buttons, call/note log, next follow-up. Admin or the enquiry's outlet staff (LeadPolicy::update). */
class EnquiryPipelineController extends Controller
{
    public const LOST_REASONS = [
        'Price too high', 'Date / slot not available', 'Chose another venue', 'No response from customer',
        'Plans cancelled', 'Just enquiring', 'Duplicate enquiry',
    ];

    public function status(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (LeadStatus $s) => $s->value, LeadStatus::options()))],
            'lost_reason' => ['nullable', 'required_if:status,lost', 'string', 'max:255'],
        ], ['lost_reason.required_if' => 'Give a reason when marking an enquiry as lost.']);

        $to = LeadStatus::from($data['status']);
        if ($to === $lead->status) {
            return back()->with('status', "Already {$to->label()}.");
        }

        $lead->changeStatus($to, $data['lost_reason'] ?? null);

        return back()->with('status', "Status changed to {$to->label()}.");
    }

    /** Log a call or a note, and (re)set the next follow-up. A call on a new enquiry moves it to contacted. */
    public function activity(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $data = $request->validate([
            'type' => ['required', Rule::in([ActivityType::Call->value, ActivityType::Note->value])],
            'body' => ['nullable', 'required_if:type,note', 'string', 'max:2000'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
            'no_follow_up' => ['boolean'],
        ], ['body.required_if' => 'Write the note first.']);

        $type = ActivityType::from($data['type']);
        $lead->logActivity($type, trim((string) ($data['body'] ?? '')) ?: null);

        if ($request->boolean('no_follow_up')) {
            $lead->next_follow_up_at = null;
        } elseif (filled($data['next_follow_up_at'] ?? null)) {
            $lead->next_follow_up_at = Carbon::parse($data['next_follow_up_at']);
        }

        if ($type === ActivityType::Call && $lead->status === LeadStatus::New) {
            $lead->changeStatus(LeadStatus::Contacted); // saves the follow-up too
        } else {
            $lead->save();
        }

        return back()->with('status', $type === ActivityType::Call ? 'Call logged.' : 'Note added.');
    }

    public function followUp(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $data = $request->validate(['next_follow_up_at' => ['nullable', 'date', 'after:now']]);
        $lead->update(['next_follow_up_at' => filled($data['next_follow_up_at'] ?? null) ? Carbon::parse($data['next_follow_up_at']) : null]);

        return back()->with('status', $lead->next_follow_up_at
            ? 'Follow-up set for '.$lead->next_follow_up_at->format('D d M, g:i a').'.'
            : 'Follow-up cleared.');
    }
}
