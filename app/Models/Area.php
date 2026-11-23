<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Bookable space in an outlet (party room, arena). */
#[Fillable(['outlet_id', 'name', 'capacity', 'is_active'])]
class Area extends Model
{
    use BelongsToOutlet;

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean'];
    }
}
