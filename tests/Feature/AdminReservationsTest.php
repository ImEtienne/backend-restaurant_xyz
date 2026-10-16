<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReservationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_without_authentication_even_without_accept_header(): void
    {
        $this->get('/api/admin/reservations')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_forbids_listing_and_updates_for_non_admin_users(): void
    {
        $user = User::factory()->create();
        $reservation = Reservation::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/admin/reservations')->assertForbidden();
        $this->patchJson('/api/admin/reservations/'.$reservation->id, ['status' => 'confirmed'])->assertForbidden();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }

    public function test_lists_paginated_reservations_with_contact_details_for_admins(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        Reservation::factory()->count(21)->create(['date' => '2027-01-01', 'time' => '19:30']);

        $response = $this->actingAs($admin)->getJson('/api/admin/reservations');

        $response->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)
            ->assertJsonPath('data.0.time', '19:30')->assertJsonStructure(['data' => [['id', 'name', 'email', 'phone', 'date', 'time', 'guests', 'status']], 'links', 'meta']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs($admin)->getJson('/api/admin/reservations?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_combines_date_and_status_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $match = Reservation::factory()->create(['date' => '2027-01-01', 'status' => 'pending']);
        Reservation::factory()->create(['date' => '2027-01-02', 'status' => 'pending']);
        Reservation::factory()->create(['date' => '2027-01-01', 'status' => 'confirmed']);

        $this->actingAs($admin)->getJson('/api/admin/reservations?date=2027-01-01&status=pending')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_rejects_invalid_filters_with_422(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/admin/reservations?date=invalid&status=invalid')->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'status']);
    }

    #[DataProvider('decisions')]
    public function test_updates_only_pending_reservations_and_ignores_other_fields(string $status): void
    {
        $admin = User::factory()->admin()->create();
        $reservation = Reservation::factory()->create(['name' => 'Original']);

        $this->actingAs($admin)->patchJson('/api/admin/reservations/'.$reservation->id, [
            'status' => $status, 'name' => 'Changed', 'is_admin' => true,
        ])->assertOk()->assertJsonPath('data.status', $status)->assertJsonPath('data.name', 'Original');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => $status, 'name' => 'Original']);
    }

    public static function decisions(): array
    {
        return ['confirmation' => ['confirmed'], 'refusal' => ['rejected']];
    }

    public function test_returns_409_without_overwriting_a_processed_reservation(): void
    {
        $reservation = Reservation::factory()->create(['status' => 'confirmed']);

        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/reservations/'.$reservation->id, ['status' => 'rejected'])
            ->assertConflict()->assertJsonPath('message', 'Cette demande a deja ete traitee.');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'confirmed']);
    }

    public function test_rejects_invalid_status_without_changing_the_reservation(): void
    {
        $reservation = Reservation::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/reservations/'.$reservation->id, ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }

    public function test_returns_404_for_a_missing_reservation(): void
    {
        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/reservations/9999', ['status' => 'confirmed'])->assertNotFound();
    }

    public function test_returns_401_for_an_unauthenticated_update(): void
    {
        $reservation = Reservation::factory()->create();

        $this->patchJson('/api/admin/reservations/'.$reservation->id, ['status' => 'confirmed'])->assertUnauthorized();

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }
}
