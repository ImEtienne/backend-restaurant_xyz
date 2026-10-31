<?php

namespace App\Models;

use Database\Factories\RestaurantServiceExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'service_key',
    'service_name',
    'start_date',
    'end_date',
    'is_closed',
    'opens_at',
    'closes_at',
    'slot_interval_minutes',
    'duration_minutes',
    'capacity_guests',
])]
class RestaurantServiceException extends Model
{
    /** @use HasFactory<RestaurantServiceExceptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
            'slot_interval_minutes' => 'integer',
            'duration_minutes' => 'integer',
            'capacity_guests' => 'integer',
        ];
    }
}
