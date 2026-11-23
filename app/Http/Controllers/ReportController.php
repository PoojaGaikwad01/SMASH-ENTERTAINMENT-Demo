<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Services\BookingExport\BookingCsv;
use App\Support\Csv;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Two tabs over one date range (+ outlet for admin), totals computed in PHP, tables + CSS bars (no chart library):
 * - enquiries (?tab=enquiries, default): by received date — source / outlet / status / type / lost reasons / day,
 *   conversion (= confirmed + completed), average time to reply (replied_at − assigned_at).
 * - bookings (?tab=bookings): by event date — value, advance, open balance, by outlet / package / area / status / day.
 * CSV export per tab (bookings use the BookingCsv layout shared with the POS export). Outlet staff only see their outlet.
 */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        [$f, $from, $to] = $this->filters($request);
        $branches = $request->user()->isAdmin() ? Branch::orderBy('name')->get(['id', 'name', 'is_active']) : collect();

        return view('reports.index', ['from' => $from, 'to' => $to, 'branches' => $branches, 'tab' => $f['tab']]
            + ($f['tab'] === 'bookings' ? $this->bookingReport($request, $f, $from, $to, $branches) : $this->enquiryReport($request, $f, $from, $to, $branches)));
    }

    public function export(Request $request)
    {
        [$f, $from, $to] = $this->filters($request);
        $range = $from->format('Ymd').'-'.$to->format('Ymd');

        if ($f['tab'] === 'bookings') {
            return $this->csv("bookings-{$range}.csv", BookingCsv::headers(),
                $this->bookingQuery($request, $f, $from, $to)->with(BookingCsv::WITH), fn (Booking $b) => BookingCsv::row($b));
        }

        return $this->csv("leads-{$range}.csv",
            ['ID', 'Received', 'Source', 'Status', 'Name', 'Phone', 'Email', 'City', 'Outlet', 'Agency',
                'Campaign', 'Ad', 'Form', 'Assigned at', 'Assigned by', 'Replied at', 'Minutes to reply', 'Reminders',
                'Enquiry type', 'Preferred date', 'Guests', 'Lost reason', 'Next follow-up', 'Entered by', 'Bookings'],
            $this->leadQuery($request, $f, $from, $to)->with(['branch:id,name', 'assignedBy:id,name', 'createdBy:id,name'])->withCount(['reminders', 'bookings']),
            fn (Lead $l) => [
                $l->id, $l->created_at->format('Y-m-d H:i'), $l->source->label(), $l->status->label(),
                $l->name, $l->phone, $l->email, $l->city, $l->branch?->name, $l->agency_name,
                $l->campaign_name, $l->ad_name, $l->form_name,
                $l->assigned_at?->format('Y-m-d H:i'), $l->assignedBy?->name, $l->replied_at?->format('Y-m-d H:i'),
                $this->minutesToReply($l), $l->reminders_count,
                $l->enquiry_type->label(), $l->preferred_date?->toDateString(), $l->guest_count, $l->lost_reason,
                $l->next_follow_up_at?->format('Y-m-d H:i'), $l->createdBy?->name, $l->bookings_count,
            ]);
    }

    private function enquiryReport(Request $request, array $f, Carbon $from, Carbon $to, Collection $branches): array
    {
        $leads = $this->leadQuery($request, $f, $from, $to)->withCount('reminders')
            ->get(['id', 'source', 'status', 'branch_id', 'assigned_at', 'replied_at', 'created_at', 'enquiry_type', 'lost_reason']);

        return [
            'totals' => $this->summarize($leads) + ['needs_review' => $leads->where('status', LeadStatus::NeedsReview)->count()],
            'bySource' => collect(LeadSource::options())->mapWithKeys(fn ($s) => [$s->label() => $this->summarize($leads->where('source', $s))]),
            'byBranch' => $branches->isNotEmpty()
                ? $branches->mapWithKeys(fn ($b) => [$b->name => $this->summarize($leads->where('branch_id', $b->id))])
                    ->put('Unassigned', $this->summarize($leads->whereNull('branch_id')))
                : collect(),
            'byStatus' => collect(LeadStatus::options())->mapWithKeys(fn ($s) => [$s->label() => $leads->where('status', $s)->count()]),
            'byType' => collect(EnquiryType::cases())->mapWithKeys(fn ($t) => [$t->label() => $this->summarize($leads->where('enquiry_type', $t))]),
            'lostReasons' => $leads->where('status', LeadStatus::Lost)->countBy(fn ($l) => $l->lost_reason ?: 'No reason given')->sortDesc(),
            'byDay' => $leads->groupBy(fn ($l) => $l->created_at->toDateString())->map->count()->sortKeysDesc(),
        ];
    }

    private function bookingReport(Request $request, array $f, Carbon $from, Carbon $to, Collection $branches): array
    {
        $bookings = $this->bookingQuery($request, $f, $from, $to)->with(['outlet:id,name', 'area:id,name', 'package:id,name'])->get();
        $live = $bookings->reject(fn (Booking $b) => $b->status === BookingStatus::Cancelled);
        $sum = fn (Collection $c) => [
            'count' => $c->count(),
            'value' => (float) $c->sum('total_amount'),
            'advance' => (float) $c->sum('advance_paid'),
            'guests' => (int) $c->sum('guest_count'),
        ];
        $hours = fn (Collection $c) => round($c->sum(fn (Booking $b) => $b->startsAt()->diffInMinutes($b->endsAt())) / 60, 1);
        $count = fn (BookingStatus ...$s) => $bookings->filter(fn (Booking $b) => in_array($b->status, $s, true))->count();

        return [
            'btotals' => $sum($live) + [
                'confirmed' => $count(BookingStatus::Confirmed, BookingStatus::Completed),
                'tentative' => $count(BookingStatus::Tentative),
                'cancelled' => $count(BookingStatus::Cancelled),
                'balance_open' => (float) $live->filter(fn (Booking $b) => $b->status->holdsSlot())->sum('balance_due'),
                'avg_value' => $live->isNotEmpty() ? $live->sum('total_amount') / $live->count() : null,
            ],
            'byOutlet' => $branches->mapWithKeys(fn ($b) => [$b->name => $sum($live->where('outlet_id', $b->id))]),
            'byPackage' => $live->groupBy(fn (Booking $b) => $b->package?->name ?? 'Custom (no package)')->map($sum)->sortByDesc('value'),
            'byArea' => $live->groupBy(fn (Booking $b) => $b->area?->name.($branches->isNotEmpty() ? ' · '.$b->outlet?->name : ''))
                ->map(fn ($c) => $sum($c) + ['hours' => $hours($c)])->sortByDesc('hours'),
            'byBookingStatus' => collect(BookingStatus::cases())->mapWithKeys(fn ($s) => [$s->label() => $sum($bookings->where('status', $s))]),
            'byEventDay' => $live->groupBy(fn (Booking $b) => $b->event_date->toDateString())->map($sum)->sortKeys(),
            'cancelReasons' => $bookings->where('status', BookingStatus::Cancelled)->countBy(fn ($b) => $b->cancel_reason ?: 'No reason given')->sortDesc(),
        ];
    }

    /** @return array{0: array, 1: Carbon, 2: Carbon} validated filters (+ tab, branch), from, to */
    private function filters(Request $request): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable'],
            'source' => ['nullable', Rule::enum(LeadSource::class)],
            'tab' => ['nullable', Rule::in(['enquiries', 'bookings'])],
        ]);
        $f['tab'] ??= 'enquiries';
        $f['branch'] = $request->user()->isAdmin() ? ($f['branch_id'] ?? null) : null;

        // Defaults: enquiries = last 30 days received; bookings = last 30 + next 30 days of events.
        $from = Carbon::parse($f['from'] ?? now()->subDays(29))->startOfDay();
        $to = Carbon::parse($f['to'] ?? ($f['tab'] === 'bookings' ? now()->addDays(30) : now()))->endOfDay();

        return [$f, $from, $to];
    }

    private function leadQuery(Request $request, array $f, Carbon $from, Carbon $to): Builder
    {
        return Lead::visibleTo($request->user())
            ->whereBetween('leads.created_at', [$from, $to])
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($f['branch'], fn ($q, $v) => $v === 'none' ? $q->whereNull('branch_id') : $q->where('branch_id', (int) $v));
    }

    private function bookingQuery(Request $request, array $f, Carbon $from, Carbon $to): Builder
    {
        return Booking::visibleTo($request->user())
            ->whereDate('event_date', '>=', $from->toDateString())
            ->whereDate('event_date', '<=', $to->toDateString())
            ->when($f['branch'], fn ($q, $v) => $q->where('outlet_id', $v === 'none' ? 0 : (int) $v));
    }

    /** Streams a UTF-8 (BOM) formula-safe CSV in chunks of 500. */
    private function csv(string $filename, array $headers, Builder $query, Closure $row)
    {
        return response()->streamDownload(function () use ($headers, $query, $row) {
            $out = fopen('php://output', 'w');
            fwrite($out, Csv::BOM);
            Csv::put($out, $headers);
            $query->chunkById(500, fn ($items) => $items->each(fn ($item) => Csv::put($out, $row($item))));
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{total: int, pending: int, replied: int, converted: int, lost: int, avg_reply: ?float, reminders: int} */
    private function summarize(Collection $leads): array
    {
        return [
            'total' => $leads->count(),
            'pending' => $leads->filter(fn (Lead $l) => $l->awaitingReply())->count(),
            'replied' => $leads->whereNotNull('replied_at')->count(),
            'converted' => $leads->filter(fn (Lead $l) => in_array($l->status, [LeadStatus::Confirmed, LeadStatus::Completed], true))->count(),
            'lost' => $leads->where('status', LeadStatus::Lost)->count(),
            'avg_reply' => $leads->map(fn ($l) => $this->minutesToReply($l))->filter(fn ($m) => $m !== null)->avg(),
            'reminders' => (int) $leads->sum('reminders_count'),
        ];
    }

    private function minutesToReply(Lead $lead): ?int
    {
        return $lead->assigned_at && $lead->replied_at ? (int) max(0, $lead->assigned_at->diffInMinutes($lead->replied_at)) : null;
    }
}
