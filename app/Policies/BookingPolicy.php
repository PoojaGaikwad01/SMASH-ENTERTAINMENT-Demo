<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /** Admin: any booking. Outlet staff (manager / front desk): their outlet's bookings. */
    public function view(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || ($user->branch_id !== null && $booking->outlet_id === $user->branch_id);
    }

    /** Edit / confirm / complete / cancel. */
    public function update(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }
}
