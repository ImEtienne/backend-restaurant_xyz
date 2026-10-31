<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\RestaurantServiceException;
use App\Models\RestaurantServiceSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_configured_slots_and_remaining_guest_capacity(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $date = '2026-11-02';
        $this->createSchedule([
            'day_of_week' => 1,
            'opens_at' => '18:00',
            'closes_at' => '21:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 6,
        ]);
        Reservation::factory()->create([
            'date' => $date,
            'time' => '18:00',
            'guests' => 4,
            'duration_minutes' => 90,
        ]);

        $this->getJson('/api/availability?date='.$date)
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.time', '18:00')
            ->assertJsonPath('data.0.remaining_capacity', 2)
            ->assertJsonPath('data.1.time', '18:30')
            ->assertJsonPath('data.1.remaining_capacity', 2)
            ->assertJsonPath('data.3.time', '19:30')
            ->assertJsonPath('data.3.remaining_capacity', 6)
            ->assertJsonPath('meta.timezone', 'Europe/Paris');
    }

    public function test_rejects_an_overlapping_booking_that_exceeds_capacity(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $date = '2026-11-02';
        $this->createSchedule([
            'day_of_week' => 1,
            'opens_at' => '18:00',
            'closes_at' => '21:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 6,
        ]);
        Reservation::factory()->create([
            'date' => $date,
            'time' => '18:00',
            'guests' => 4,
            'duration_minutes' => 90,
        ]);

        $this->postJson('/api/reservations', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+33123456789',
            'date' => $date,
            'time' => '18:30',
            'service_key' => 'dinner',
            'guests' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('guests');

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_public_booking_requires_a_service_key_when_services_share_a_slot(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $date = '2026-11-02';
        $this->createSchedule([
            'service_key' => 'lunch',
            'service_name' => 'Dejeuner',
            'day_of_week' => 1,
            'opens_at' => '12:00',
            'closes_at' => '14:00',
            'slot_interval_minutes' => 60,
            'duration_minutes' => 60,
        ]);
        $this->createSchedule([
            'service_key' => 'special',
            'service_name' => 'Special',
            'day_of_week' => 1,
            'opens_at' => '12:00',
            'closes_at' => '14:00',
            'slot_interval_minutes' => 60,
            'duration_minutes' => 60,
        ]);

        $this->postJson('/api/reservations', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+33123456789',
            'date' => $date,
            'time' => '12:00',
            'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('service_key');
    }

    public function test_client_creation_and_update_share_the_live_capacity_check(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $date = '2026-11-02';
        $this->createSchedule([
            'day_of_week' => 1,
            'opens_at' => '18:00',
            'closes_at' => '21:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 2,
        ]);
        $client = User::factory()->create();

        $created = $this->actingAs($client)->postJson('/api/client/reservations', [
            'name' => 'Ada Lovelace',
            'phone' => '+33123456789',
            'date' => $date,
            'time' => '18:00',
            'service_key' => 'dinner',
            'guests' => 2,
        ])->assertCreated();
        $reservationId = $created->json('data.id');

        $this->patchJson('/api/client/reservations/'.$reservationId, ['guests' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guests');
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'guests' => 2,
            'service_key' => 'dinner',
            'duration_minutes' => 90,
        ]);
    }

    public function test_booking_is_rejected_when_the_requested_time_is_outside_service_hours(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $this->createSchedule([
            'day_of_week' => 1,
            'opens_at' => '18:00',
            'closes_at' => '21:00',
        ]);

        $this->postJson('/api/reservations', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+33123456789',
            'date' => '2026-11-02',
            'time' => '17:30',
            'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_booking_is_rejected_until_the_restaurant_configures_a_service(): void
    {
        $this->travelTo('2026-10-30 09:00:00');

        $this->postJson('/api/reservations', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+33123456789',
            'date' => '2026-11-02',
            'time' => '19:30',
            'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_exceptional_hours_replace_the_weekly_service_schedule(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $date = '2026-11-02';
        $this->createSchedule(['day_of_week' => 1]);
        RestaurantServiceException::query()->create([
            'service_key' => 'dinner',
            'service_name' => 'Service exceptionnel',
            'start_date' => $date,
            'end_date' => $date,
            'is_closed' => false,
            'opens_at' => '12:00',
            'closes_at' => '14:00',
            'slot_interval_minutes' => 60,
            'duration_minutes' => 60,
            'capacity_guests' => 4,
        ]);

        $this->getJson('/api/availability?date='.$date)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.time', '12:00')
            ->assertJsonPath('data.0.service_name', 'Service exceptionnel')
            ->assertJsonPath('data.1.time', '13:00');
    }

    public function test_global_closure_exception_removes_all_slots_for_the_period(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $date = '2026-11-02';
        $this->createSchedule(['day_of_week' => 1]);
        RestaurantServiceException::query()->create([
            'service_key' => '*',
            'start_date' => $date,
            'end_date' => '2026-11-04',
            'is_closed' => true,
        ]);

        $this->getJson('/api/availability?date='.$date)->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['date' => $date, 'timezone' => 'Europe/Paris'],
        ]);
    }

    public function test_availability_rejects_invalid_or_past_dates(): void
    {
        $this->travelTo('2026-10-30 09:00:00');

        $this->getJson('/api/availability?date=invalid')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/availability?date=2026-10-29')->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    /** @param array<string, mixed> $overrides */
    private function createSchedule(array $overrides = []): RestaurantServiceSchedule
    {
        return RestaurantServiceSchedule::query()->create(array_replace([
            'service_key' => 'dinner',
            'service_name' => 'Diner',
            'day_of_week' => 1,
            'opens_at' => '18:00',
            'closes_at' => '21:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 6,
        ], $overrides));
    }
}
