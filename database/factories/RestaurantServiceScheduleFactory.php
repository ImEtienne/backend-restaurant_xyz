<?php

namespace Database\Factories;

use App\Models\RestaurantServiceSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantServiceSchedule>
 */
class RestaurantServiceScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_key' => fake()->unique()->slug(2),
            'service_name' => fake()->word(),
            'day_of_week' => fake()->numberBetween(1, 7),
            'opens_at' => '18:00',
            'closes_at' => '22:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 40,
        ];
    }
}
