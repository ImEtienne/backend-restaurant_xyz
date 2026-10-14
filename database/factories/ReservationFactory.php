<?php

namespace Database\Factories;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '+33123456789',
            'date' => now(config('reservations.timezone'))->addDay()->toDateString(),
            'time' => '19:30',
            'guests' => fake()->numberBetween(1, 10),
        ];
    }
}