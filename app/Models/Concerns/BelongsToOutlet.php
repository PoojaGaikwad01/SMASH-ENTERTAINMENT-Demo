<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For the Smash tables keyed by outlet_id (areas, packages, addons, bookings). Outlets live in `branches`.
 * Same rule as BelongsToBranch: admin sees all, outlet staff only their own outlet.
 */
trait BelongsToOutlet
{
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'outlet_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->where($this->qualifyColumn('outlet_id'), $user->branch_id ?? 0);
        }
    }

    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }
}
