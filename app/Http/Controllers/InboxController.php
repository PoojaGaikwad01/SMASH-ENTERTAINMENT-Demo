<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InboxController extends Controller
{
    public const FILTERS = ['q', 'source', 'status', 'enquiry_type', 'branch_id', 'from', 'to'];

    public function index(Request $request)
    {
        return view('inbox.index', $this->listData($request) + ['lead' => null]);
    }

    public function show(Request $request, Lead $lead)
    {
        Gate::authorize('view', $lead);

        $lead->load(['messages.sender:id,name', 'activities.user:id,name', 'branch:id,name,city', 'assignedBy:id,name', 'createdBy:id,name', 'reminders', 'bookings']);

        return view('inbox.index', $this->listData($request) + ['lead' => $lead, 'window' => $lead->replyWindow()]);
    }

    /** Left pane: filtered, outlet-scoped enquiry list + per-status counts. view=due → follow-ups due today (incl. overdue), soonest first. */
    private function listData(Request $request): array
    {
        $user = $request->user();
        $filters = $request->only(self::FILTERS);
        $due = $request->query('view') === 'due';

        $base = fn () => Lead::visibleTo($user)->when($due, fn ($q) => $q->followUpsDue());

        $leads = $base()
            ->filter($filters)
            ->with(['latestMessage', 'branch:id,name'])
            ->when($due,
                fn ($q) => $q->orderBy('next_follow_up_at')->orderBy('id'),
                fn ($q) => $q->orderByDesc('last_activity_at')->orderByDesc('id'))
            ->paginate(25)
            ->withQueryString();

        $statusCounts = $base()
            ->filter(array_diff_key($filters, ['status' => 1]))
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $branches = $user->isAdmin() ? Branch::orderBy('name')->get(['id', 'name', 'is_active']) : collect();

        return compact('leads', 'statusCounts', 'branches', 'due');
    }
}
