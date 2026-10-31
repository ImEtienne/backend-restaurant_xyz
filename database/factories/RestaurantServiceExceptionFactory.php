<?php

namespace Database\Factories;

use App\Models\RestaurantServiceException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantServiceException>
 */
class RestaurantServiceExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('+1 month', '+2 months')->format('Y-m-d');

        return [
            'service_key' => fake()->unique()->slug(2),
            'service_name' => fake()->word(),
            'start_date' => $date,
            'end_date' => $date,
            'is_closed' => false,
            'opens_at' => '18:00',
            'closes_at' => '22:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 40,
        ];
    }
}
