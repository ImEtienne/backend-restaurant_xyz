<?php

namespace App\Models;

use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['registration_token_hash'])]
#[Fillable(['name', 'email', 'phone', 'date', 'time', 'guests', 'service_key', 'duration_minutes'])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'guests' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }
}
