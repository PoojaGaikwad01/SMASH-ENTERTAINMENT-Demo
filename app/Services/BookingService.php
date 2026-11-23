<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\Addon;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Package;
use App\Models\User;
use App\Services\BookingExport\BookingExportService;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * All booking writes: totals, double-booking prevention, status flow and the enquiry side effects.
 * Double booking: inside one transaction the AREA row is locked (SELECT … FOR UPDATE), so two staff booking the same
 * room at once are serialised; the second one then sees the first booking in the overlap check and is rejected.
 */
class BookingService
{
    public function __construct(private BookingNotifier $notifier, private BookingExportService $export) {}

    /** Form rules. Catalogue items must belong to the outlet and be active (the ones already on $booking stay allowed). */
    public function rules(Branch $outlet, ?Booking $booking = null): array
    {
        $own = fn (string $table, ?int $current) => Rule::exists($table, 'id')->where('outlet_id', $outlet->id)
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)));
        $keepsDate = $booking && request('event_date') === $booking->event_date?->toDateString();

        return [
            'area_id' => ['required', 'integer', $own('areas', $booking?->area_id)],
            'package_id' => ['nullable', 'integer', $own('packages', $booking?->package_id)],
            'event_date' => ['required', 'date_format:Y-m-d', ...($keepsDate ? [] : ['after_or_equal:today'])],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_without:package_id', 'date_format:H:i', 'after:start_time'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:2000'],
            'child_name' => ['nullable', 'string', 'max:100'],
            'child_age' => ['nullable', 'integer', 'min:0', 'max:18'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'addons' => ['nullable', 'array'],           // [addon_id => qty]
            'addons.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'advance_paid' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'advance_paid_was' => ['nullable', 'numeric'], // edit form: advance when it was opened (online payment guard)
        ];
    }

    /** Create ($booking null) or update a booking for an enquiry. Throws ValidationException on clash / capacity / totals. */
    public function save(Lead $lead, array $data, ?Booking $booking = null, ?User $by = null): Booking
    {
        $outletId = $booking->outlet_id ?? $lead->branch_id;
        throw_unless($outletId, ValidationException::withMessages(['lead' => 'Assign this enquiry to an outlet before booking.']));

        $area = Area::where('outlet_id', $outletId)->findOrFail($data['area_id']);
        $package = filled($data['package_id'] ?? null) ? Package::where('outlet_id', $outletId)->findOrFail($data['package_id']) : null;
        $date = Carbon::parse($data['event_date'])->toDateString();
        $time = fn (string $t) => Carbon::createFromFormat('!H:i', substr($t, 0, 5)); // '!' = no leftover seconds from "now"
        $start = $time($data['start_time']);
        $end = filled($data['end_time'] ?? null) ? $time($data['end_time']) : $start->copy()->addMinutes($package?->duration_minutes ?? 0);
        $guests = (int) $data['guest_count'];

        $errors = array_filter([
            'end_time' => ! $end->isSameDay($start) || $end->lte($start) ? 'The booking must end after it starts, on the same day.' : null,
            'guest_count' => match (true) {
                $area->capacity > 0 && $guests > $area->capacity => "{$area->name} holds up to {$area->capacity} guests.",
                $package?->max_guests && $guests > $package->max_guests => "{$package->name} is for up to {$package->max_guests} guests.",
                default => null,
            },
        ]);
        throw_if($errors, ValidationException::withMessages($errors));

        $qty = collect($data['addons'] ?? [])->map(fn ($q) => (int) $q)->filter(fn ($q) => $q > 0);
        $addons = Addon::where('outlet_id', $outletId)->whereIn('id', $qty->keys())->get()->keyBy('id');
        throw_if($addons->count() !== $qty->count(), ValidationException::withMessages(['addons' => 'Unknown add-on for this outlet.']));

        $event = $booking ? 'updated' : 'created';
        $saved = DB::transaction(function () use ($lead, $data, $booking, $by, $outletId, $area, $package, $date, $start, $end, $guests, $qty, $addons) {
            Area::whereKey($area->id)->lockForUpdate()->first();
            if ($booking) {
                // Locked fresh copy: an online payment (PaymentService::markPaid) may have landed since the form was opened.
                $current = Booking::whereKey($booking->id)->lockForUpdate()->value('advance_paid');
                if (isset($data['advance_paid_was']) && round((float) $data['advance_paid_was'], 2) !== round((float) $current, 2)) {
                    throw ValidationException::withMessages(['advance_paid' => 'A payment was recorded while you were editing (advance is now '
                        .Format::money($current).'). Reload the page and try again.']);
                }
                $booking->advance_paid = $current;
            }

            $clash =$this->clashes($area->id, $date, $start->format('H:i:s'), $end->format('H:i:s'), $booking?->id)->first();
            if ($clash) {
                throw ValidationException::withMessages([
                    'start_time' => "{$area->name} is already booked {$clash->time_range} on ".Carbon::parse($date)->format('D d M')." ({$clash->booking_code}).",
                ]);
            }

            // Keep the prices agreed earlier when editing; new items use today's catalogue price.
            $kept = $booking ? $booking->addons->pluck('pivot.price', 'id') : collect();
            $lines = $qty->mapWithKeys(fn ($q, $id) => [$id => ['qty' => $q, 'price' => $kept[$id] ?? $addons[$id]->price]]);
            $packagePrice = $package ? (float) ($booking && (int) $booking->package_id === $package->id ? $booking->package_price : $package->price) : 0.0;
            $total = round($packagePrice + $lines->sum(fn ($l) => $l['qty'] * (float) $l['price']), 2);
            $advance = round((float) ($data['advance_paid'] ?? $booking?->advance_paid ?? 0), 2);
            throw_if($advance > $total, ValidationException::withMessages(['advance_paid' => 'Advance can\'t be more than the total ('.Format::money($total).').']));

            $isNew = ! $booking;
            $booking ??= new Booking(['lead_id' => $lead->id, 'outlet_id' => $outletId, 'created_by_user_id' => $by?->id ?? auth()->id()]);
            $booking->fill([
                'area_id' => $area->id,
                'package_id' => $package?->id,
                'package_price' => $packagePrice,
                'event_date' => $date,
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'guest_count' => $guests,
                'child_name' => $data['child_name'] ?? null,
                'child_age' => $data['child_age'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,
                'total_amount' => $total,
                'advance_paid' => $advance,
                'balance_due' => round($total - $advance, 2),
                'payment_status' => PaymentStatus::fromAmounts($total, $advance),
            ])->save();
            $booking->addons()->sync($lines->all());

            $lead->logActivity(ActivityType::Booking, sprintf('Booking %s %s: %s · %s %s · %s',
                $booking->booking_code, $isNew ? 'created (tentative)' : 'updated', $area->name,
                $booking->event_date->format('D d M'), $booking->time_range, Format::money($total)), $by);

            return $booking->load('addons');
        });
        $this->pushExport($saved, $event);

        return $saved;
    }

    /**
     * Status flow. Confirming sets the enquiry to Confirmed and queues the confirmation (WhatsApp + email);
     * completing the last open booking sets the enquiry to Completed.
     * Cancelled bookings free their slot; the enquiry status is left for staff to decide.
     */
    public function transition(Booking $booking, BookingStatus $to, ?string $reason = null, ?User $by = null): void
    {
        if (! $booking->status->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => "A {$booking->status->label()} booking can't be marked {$to->label()}."]);
        }

        DB::transaction(function () use ($booking, $to, $reason, $by) {
            $booking->forceFill([
                'status' => $to,
                'confirmed_at' => $to === BookingStatus::Confirmed ? now() : $booking->confirmed_at,
                'cancel_reason' => $to === BookingStatus::Cancelled ? $reason : $booking->cancel_reason,
            ])->save();

            $lead = $booking->lead;
            $lead->logActivity(ActivityType::Booking, "Booking {$booking->booking_code} {$to->value}".($reason ? " · Reason: {$reason}" : ''), $by);

            if ($to === BookingStatus::Confirmed && $lead->status !== LeadStatus::Confirmed) {
                $lead->changeStatus(LeadStatus::Confirmed);
            }
            if ($to === BookingStatus::Completed && $lead->status !== LeadStatus::Completed
                && ! $lead->bookings()->holdingSlot()->exists()) {
                $lead->changeStatus(LeadStatus::Completed);
            }
        });

        if ($to === BookingStatus::Confirmed) {
            $this->notifier->notify($booking, NotificationType::Confirmation); // WhatsApp + email, queued
        }
        $this->pushExport($booking, $to->value);
    }

    /** Hand a booking change to the POS export driver (BOOKING_EXPORT_DRIVER). Never throws: exports must not block bookings. */
    public function pushExport(Booking $booking, string $event): void
    {
        try {
            $this->export->push($booking, $event);
        } catch (\Throwable $e) {
            Log::warning('Booking export failed', ['booking' => $booking->booking_code, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** Tentative/confirmed bookings in the area that overlap [start, end) on the date. Back-to-back is allowed. */
    public function clashes(int $areaId, string $date, string $start, string $end, ?int $ignoreId = null): Collection
    {
        return Booking::where('area_id', $areaId)
            ->whereDate('event_date', $date)
            ->holdingSlot()
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->orderBy('start_time')
            ->get();
    }
}
