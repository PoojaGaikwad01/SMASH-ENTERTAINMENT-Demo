<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['outlet_id', 'name', 'description', 'price', 'duration_minutes', 'min_guests', 'max_guests', 'is_active'])]
class Package extends Model
{
    use BelongsToOutlet;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'min_guests' => 'integer',
            'max_guests' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
