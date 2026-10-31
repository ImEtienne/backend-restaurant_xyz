<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\RestaurantServiceException;
use App\Models\RestaurantServiceSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminRestaurantServiceConfigurationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_schedule_management_requires_reservation_administrator_rights(): void
    {
        $this->getJson('/api/admin/service-schedules')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/service-schedules')->assertForbidden();
    }

    public function test_administrator_can_create_read_update_and_delete_a_weekly_service_schedule(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->schedulePayload();

        $created = $this->actingAs($admin)->postJson('/api/admin/service-schedules', $payload)
            ->assertCreated()
            ->assertJsonPath('data.service_key', 'dinner')
            ->assertJsonPath('data.day_of_week', 1);
        $scheduleId = $created->json('data.id');

        $this->getJson('/api/admin/service-schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $scheduleId);
        $this->putJson('/api/admin/service-schedules/' . $scheduleId, [
            'service_name' => 'Diner modifie',
            'opens_at' => $payload['opens_at'],
            'closes_at' => $payload['closes_at'],
            'slot_interval_minutes' => $payload['slot_interval_minutes'],
            'duration_minutes' => $payload['duration_minutes'],
            'capacity_guests' => $payload['capacity_guests'],
        ])->assertOk()
            ->assertJsonPath('data.service_name', 'Diner modifie')
            ->assertJsonPath('data.service_key', 'dinner')
            ->assertJsonPath('data.day_of_week', 1);

        $this->deleteJson('/api/admin/service-schedules/' . $scheduleId)->assertNoContent();
        $this->assertDatabaseMissing('restaurant_service_schedules', ['id' => $scheduleId]);
    }

    public function test_schedule_rejects_invalid_hours_and_duplicate_day_service(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->schedulePayload();
        $this->actingAs($admin)->postJson('/api/admin/service-schedules', [
            ...$payload,
            'opens_at' => '20:00',
            'closes_at' => '18:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('closes_at');
        $this->postJson('/api/admin/service-schedules', $payload)->assertCreated();
        $this->postJson('/api/admin/service-schedules', $payload)->assertUnprocessable()->assertJsonValidationErrors('service_key');

        $this->assertDatabaseCount('restaurant_service_schedules', 1);
    }

    public function test_schedule_cannot_be_deleted_while_future_reservations_use_it(): void
    {
        $this->travelTo('2026-10-30 09:00:00');
        $admin = User::factory()->admin()->create();
        $schedule = RestaurantServiceSchedule::query()->create($this->schedulePayload());
        Reservation::factory()->create([
            'date' => '2026-11-02',
            'time' => '19:30',
            'service_key' => 'dinner',
            'duration_minutes' => 90,
        ]);

        $this->actingAs($admin)->deleteJson('/api/admin/service-schedules/' . $schedule->id)
            ->assertConflict();
        $this->assertModelExists($schedule);
    }

    public function test_administrator_can_create_and_update_exceptional_service_hours(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = [
            'service_key' => 'special',
            'service_name' => 'Service exceptionnel',
            'start_date' => '2027-01-02',
            'end_date' => '2027-01-03',
            'is_closed' => false,
            'opens_at' => '12:00',
            'closes_at' => '16:00',
            'slot_interval_minutes' => 60,
            'duration_minutes' => 90,
            'capacity_guests' => 12,
        ];
        $created = $this->actingAs($admin)->postJson('/api/admin/service-exceptions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.service_name', 'Service exceptionnel');
        $exceptionId = $created->json('data.id');
        $updatePayload = $payload;
        unset($updatePayload['service_key']);

        $this->putJson('/api/admin/service-exceptions/' . $exceptionId, [
            ...$updatePayload,
            'start_date' => '2027-01-04',
            'end_date' => '2027-01-05',
        ])->assertOk()
            ->assertJsonPath('data.service_key', 'special')
            ->assertJsonPath('data.start_date', '2027-01-04');

        $this->getJson('/api/admin/service-exceptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $exceptionId);
        $this->deleteJson('/api/admin/service-exceptions/' . $exceptionId)->assertNoContent();
        $this->assertDatabaseMissing('restaurant_service_exceptions', ['id' => $exceptionId]);
    }

    public function test_exceptional_periods_reject_overlaps_and_allow_a_global_closure(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = [
            'service_key' => 'dinner',
            'service_name' => 'Diner exceptionnel',
            'start_date' => '2027-01-02',
            'end_date' => '2027-01-05',
            'is_closed' => false,
            'opens_at' => '12:00',
            'closes_at' => '16:00',
            'slot_interval_minutes' => 60,
            'duration_minutes' => 90,
            'capacity_guests' => 12,
        ];
        $this->actingAs($admin)->postJson('/api/admin/service-exceptions', $payload)->assertCreated();
        $this->postJson('/api/admin/service-exceptions', [
            ...$payload,
            'start_date' => '2027-01-05',
            'end_date' => '2027-01-07',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $this->postJson('/api/admin/service-exceptions', [
            'service_key' => '*',
            'start_date' => '2027-01-03',
            'end_date' => '2027-01-04',
            'is_closed' => true,
        ])->assertCreated()->assertJsonPath('data.service_key', '*');

        $this->assertDatabaseCount('restaurant_service_exceptions', 2);
    }

    public function test_service_configuration_mutations_require_administrator_authorization(): void
    {
        $schedule = RestaurantServiceSchedule::query()->create($this->schedulePayload());
        $exception = RestaurantServiceException::query()->create([
            'service_key' => '*',
            'start_date' => '2027-01-01',
            'end_date' => '2027-01-02',
            'is_closed' => true,
        ]);
        $client = User::factory()->create();

        $this->actingAs($client)->postJson('/api/admin/service-schedules', $this->schedulePayload())->assertForbidden();
        $this->putJson('/api/admin/service-schedules/' . $schedule->id, $this->schedulePayload())->assertForbidden();
        $this->deleteJson('/api/admin/service-schedules/' . $schedule->id)->assertForbidden();
        $this->postJson('/api/admin/service-exceptions', [
            'service_key' => '*',
            'start_date' => '2027-01-03',
            'end_date' => '2027-01-04',
            'is_closed' => true,
        ])->assertForbidden();
        $this->putJson('/api/admin/service-exceptions/' . $exception->id, [
            'start_date' => '2027-01-03',
            'end_date' => '2027-01-04',
            'is_closed' => true,
        ])->assertForbidden();
        $this->deleteJson('/api/admin/service-exceptions/' . $exception->id)->assertForbidden();

        $this->assertModelExists($schedule);
        $this->assertModelExists($exception);
    }

    /** @return array<string, mixed> */
    private function schedulePayload(): array
    {
        return [
            'service_key' => 'dinner',
            'service_name' => 'Diner',
            'day_of_week' => 1,
            'opens_at' => '18:00',
            'closes_at' => '22:00',
            'slot_interval_minutes' => 30,
            'duration_minutes' => 90,
            'capacity_guests' => 40,
        ];
    }
}
