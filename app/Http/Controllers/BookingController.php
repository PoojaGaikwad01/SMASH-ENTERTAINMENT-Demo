<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\EnquiryType;
use App\Enums\NotificationType;
use App\Models\Addon;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\NotificationLog;
use App\Models\Package;
use App\Services\BookingNotifier;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Bookings are created from an enquiry (LeadPolicy::update) and then managed under BookingPolicy (admin or own outlet). */
class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    /** List: q (code / name / phone), status, outlet (admin), from/to event date. "from" defaults to today (upcoming). */
    public function index(Request $request)
    {
        $user = $request->user();
        $from = $request->has('from') ? (string) $request->query('from') : today()->toDateString();
        $date = fn ($v) => is_string($v) && trim($v) !== '' ? rescue(fn () => Carbon::parse($v)->toDateString(), null, false) : null;

        $bookings = Booking::visibleTo($user)
            ->with(['lead:id,name,phone', 'area:id,name', 'package:id,name', 'outlet:id,name'])
            ->search($request->query('q'))
            ->when(BookingStatus::tryFrom((string) $request->query('status')), fn ($q, $s) => $q->where('status', $s->value))
            ->when($user->isAdmin() ? $request->integer('outlet') : null, fn ($q, $id) => $q->where('outlet_id', $id))
            ->when($date($from), fn ($q, $d) => $q->whereDate('event_date', '>=', $d))
            ->when($date($request->query('to')), fn ($q, $d) => $q->whereDate('event_date', '<=', $d))
            ->orderBy('event_date')->orderBy('start_time')
            ->paginate(25)->withQueryString();

        return view('bookings.index', [
            'bookings' => $bookings,
            'from' => $from,
            'outlets' => $user->isAdmin() ? Branch::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function create(Lead $lead)
    {
        Gate::authorize('update', $lead);
        if (! $lead->branch_id) {
            return redirect()->route('inbox.show', $lead)->withErrors(['lead' => 'Assign this enquiry to an outlet before booking.']);
        }

        return $this->form($lead, new Booking([
            'outlet_id' => $lead->branch_id,
            'event_date' => $lead->preferred_date?->isFuture() || $lead->preferred_date?->isToday() ? $lead->preferred_date : null,
            'guest_count' => $lead->guest_count,
            'special_requests' => $lead->notes,
        ]));
    }

    public function store(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);
        abort_unless($lead->branch, 422, 'Assign this enquiry to an outlet before booking.');

        $data = $request->validate($this->bookings->rules($lead->branch));
        $booking = $this->bookings->save($lead, $data, null, $request->user());

        return redirect()->route('bookings.show', $booking)->with('status', "Booking {$booking->booking_code} created as tentative.");
    }

    public function show(Booking $booking)
    {
        Gate::authorize('view', $booking);
        $booking->load(['lead', 'area', 'package', 'addons', 'outlet', 'createdBy:id,name', 'notificationLogs', 'paymentLinks']);

        return view('bookings.show', compact('booking'));
    }

    public function edit(Booking $booking)
    {
        Gate::authorize('update', $booking);
        if (! $booking->status->holdsSlot()) {
            return redirect()->route('bookings.show', $booking)->withErrors(['status' => "{$booking->status->label()} bookings can’t be edited."]);
        }

        return $this->form($booking->lead, $booking->load('addons'));
    }

    public function update(Request $request, Booking $booking)
    {
        Gate::authorize('update', $booking);
        abort_unless($booking->status->holdsSlot(), 422, 'This booking can no longer be edited.');

        $data = $request->validate($this->bookings->rules($booking->outlet, $booking));
        $this->bookings->save($booking->lead, $data, $booking->load('addons'), $request->user());

        return redirect()->route('bookings.show', $booking)->with('status', $booking->status === BookingStatus::Confirmed
            ? 'Booking updated. The customer is not notified automatically — use “Resend” on the confirmation if details changed.'
            : 'Booking updated.');
    }

    /** Manual send / Resend of a customer message (always a new attempt). channel empty = WhatsApp + email. */
    public function notify(Request $request, Booking $booking, BookingNotifier $notifier)
    {
        Gate::authorize('update', $booking);
        $data = $request->validate([
            'type' => ['required', Rule::enum(NotificationType::class)],
            'channel' => ['nullable', Rule::in(NotificationLog::CHANNELS)],
        ]);
        $type = NotificationType::from($data['type']);
        if (! $type->allowedFor($booking->status)) {
            return back()->withErrors(['type' => "A {$booking->status->value} booking can’t get a {$type->label()} message."]);
        }

        $logs = $notifier->notify($booking, $type, isset($data['channel']) ? [$data['channel']] : null, force: true);

        return back()->with('status', $type->label().' queued on '.$logs->map->channelLabel(true)->join(' + ').'.');
    }

    /** Confirm / complete / cancel (reason required). Confirming marks the enquiry Confirmed. */
    public function status(Request $request, Booking $booking)
    {
        Gate::authorize('update', $booking);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (BookingStatus $s) => $s->value, $booking->status->next()))],
            'cancel_reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:255'],
        ], ['status.in' => "A {$booking->status->value} booking can’t be changed to that status."]);

        $to = BookingStatus::from($data['status']);
        $this->bookings->transition($booking, $to, $to === BookingStatus::Cancelled ? $data['cancel_reason'] : null, $request->user());

        return back()->with('status', match ($to) {
            BookingStatus::Confirmed => "Booking {$booking->booking_code} confirmed. Enquiry marked Confirmed. Confirmation queued on WhatsApp + email.",
            BookingStatus::Completed => "Booking {$booking->booking_code} completed.",
            default => "Booking {$booking->booking_code} cancelled. The slot is free again.",
        });
    }

    private function form(Lead $lead, Booking $booking)
    {
        $outletId = $booking->outlet_id ?? $lead->branch_id;
        // Active items, plus whatever this booking already uses (even if deactivated since).
        $keep = fn (string $model, array $ids) => $model::where('outlet_id', $outletId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_filter($ids)))->orderBy('name')->get();

        $packages = $keep(Package::class, [$booking->package_id]);
        if (! $booking->exists && ! $booking->package_id) {
            // Pre-select a package matching the enquiry type (birthday → "Birthday …").
            $word = match ($lead->enquiry_type) {
                EnquiryType::Birthday => 'birthday', EnquiryType::SchoolGroup => 'school', EnquiryType::Corporate => 'corporate', default => null,
            };
            $booking->package_id = $word ? $packages->first(fn ($p) => str_contains(strtolower($p->name), $word))?->id : null;
        }

        return view('bookings.form', [
            'lead' => $lead,
            'booking' => $booking,
            'areas' => $keep(Area::class, [$booking->area_id]),
            'packages' => $packages,
            'addons' => $keep(Addon::class, $booking->exists ? $booking->addons->pluck('id')->all() : []),
        ]);
    }
}
