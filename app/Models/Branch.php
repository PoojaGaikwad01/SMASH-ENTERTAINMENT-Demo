<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An outlet (UI label). Table/columns keep the "branch" name to avoid churn. */
#[Fillable(['name', 'slug', 'city', 'is_active'])]
class Branch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Branch $branch) {
            if (blank($branch->slug)) {
                $base = Str::slug($branch->name) ?: 'outlet';
                $slug = $base;
                for ($i = 2; static::where('slug', $slug)->whereKeyNot($branch->getKey())->exists(); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $branch->slug = $slug;
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** Outlet managers + front desk of this outlet. */
    public function staff(): HasMany
    {
        return $this->users()->whereIn('role', Role::outletRoleValues());
    }

    public function managers(): HasMany
    {
        return $this->users()->where('role', Role::OutletManager);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class, 'outlet_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class, 'outlet_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'outlet_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class, 'outlet_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Admin sees every outlet; outlet staff only their own. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->whereKey($user->branch_id ?? 0);
        }
    }
}
