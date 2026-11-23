<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    /** Admin: any enquiry. Outlet staff (manager / front desk): only enquiries of their outlet. */
    public function view(User $user, Lead $lead): bool
    {
        return $user->isAdmin() || ($lead->branch_id !== null && $lead->branch_id === $user->branch_id);
    }

    /** Edit enquiry details: anyone who can view it. */
    public function update(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    /** Reply / mark contacted / mark lost: anyone who can view it, once it has an outlet. */
    public function reply(User $user, Lead $lead): bool
    {
        return $lead->branch_id !== null && $this->view($user, $lead);
    }
}
