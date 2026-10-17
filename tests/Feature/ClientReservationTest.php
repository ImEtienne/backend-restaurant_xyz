<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientReservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_client_sees_only_owned_reservations_even_when_email_matches_another_booking(): void
    {
        $client = User::factory()->create();
        $own = Reservation::factory()->create(['user_id' => $client->id]);
        $foreign = Reservation::factory()->create(['email' => $client->email]);
        $this->actingAs($client)->getJson('/api/client/reservations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/client/reservations/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/client/reservations/'.$foreign->id, ['guests' => 4])->assertNotFound();
        $this->postJson('/api/client/reservations/'.$foreign->id.'/cancel')->assertNotFound();
    }

    public function test_client_can_modify_only_their_future_pending_booking(): void
    {
        $this->freezeTime();
        $client = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $client->id, 'status' => 'pending']);
        $this->actingAs($client)->patchJson('/api/client/reservations/'.$booking->id, ['guests' => 5])->assertOk()->assertJsonPath('data.guests', 5);
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'guests' => 5, 'status' => 'pending', 'user_id' => $client->id]);
    }

    public function test_client_cannot_change_status_ownership_or_contact_email(): void
    {
        $client = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $client->id]);
        $this->actingAs($client)->patchJson('/api/client/reservations/'.$booking->id, ['status' => 'confirmed', 'user_id' => 999, 'email' => 'other@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'user_id', 'email']);
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'status' => 'pending', 'user_id' => $client->id]);
    }

    #[DataProvider('lockedStatuses')]
    public function test_processed_reservations_cannot_be_modified_or_cancelled(string $status): void
    {
        $client = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $client->id, 'status' => $status]);
        $this->actingAs($client)->patchJson('/api/client/reservations/'.$booking->id, ['guests' => 5])->assertConflict();
        $this->postJson('/api/client/reservations/'.$booking->id.'/cancel')->assertConflict();
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'status' => $status]);
    }

    public static function lockedStatuses(): array
    {
        return [['confirmed'], ['rejected'], ['cancelled']];
    }

    public function test_cancels_future_pending_bookings_without_deleting_them(): void
    {
        $client = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $client->id]);
        $this->actingAs($client)->postJson('/api/client/reservations/'.$booking->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_past_booking_cannot_be_cancelled_or_rescheduled_into_the_future(): void
    {
        $client = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $client->id, 'date' => now()->subDay()->toDateString()]);
        $this->actingAs($client)->postJson('/api/client/reservations/'.$booking->id.'/cancel')->assertConflict();
        $this->patchJson('/api/client/reservations/'.$booking->id, ['date' => now()->addDay()->toDateString()])->assertConflict();
    }

    public function test_stats_include_only_the_clients_reservations_and_cancelled_count(): void
    {
        $this->freezeTime();
        $client = User::factory()->create();
        foreach (['pending', 'confirmed', 'rejected', 'cancelled'] as $status) {
            Reservation::factory()->create(['user_id' => $client->id, 'status' => $status, 'guests' => 2]);
        }
        Reservation::factory()->create(['guests' => 10, 'status' => 'confirmed']);
        $this->actingAs($client)->getJson('/api/client/stats')->assertOk()->assertExactJson([
            'data' => ['total_reservations' => 4, 'pending' => 1, 'confirmed' => 1, 'rejected' => 1, 'cancelled' => 1, 'upcoming' => 2, 'total_guests' => 8],
            'meta' => ['timezone' => 'Europe/Paris'],
        ]);
    }

    public function test_empty_client_stats_are_zero_and_guests_have_no_access(): void
    {
        $this->getJson('/api/client/stats')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/client/stats')->assertOk()->assertJsonPath('data.total_reservations', 0);
    }

    public function test_client_can_make_a_new_booking_with_ownership_set_by_the_server(): void
    {
        $this->freezeTime();
        $client = User::factory()->create();
        $this->actingAs($client)->postJson('/api/client/reservations', ['name' => 'Client', 'email' => 'spoof@example.com', 'phone' => '0123456789',
            'date' => now()->addDay()->toDateString(), 'time' => '19:30', 'guests' => 2, 'user_id' => 999, 'status' => 'confirmed'])
            ->assertCreated()->assertJsonPath('data.email', $client->email)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('reservations', ['user_id' => $client->id, 'email' => $client->email, 'status' => 'pending']);
    }

    public function test_claim_token_is_single_use_and_does_not_follow_email_changes(): void
    {
        $client = User::factory()->create();
        $token = str_repeat('a', 64);
        $booking = Reservation::factory()->create(['email' => $client->email, 'registration_token_hash' => hash('sha256', $token)]);
        $this->actingAs($client)->postJson('/api/client/reservations/claim', ['registration_token' => $token])->assertOk();
        $this->postJson('/api/client/reservations/claim', ['registration_token' => $token])->assertNotFound();
        $client->email = 'changed@example.com';
        $client->save();
        $this->getJson('/api/client/reservations/'.$booking->id)->assertOk();
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'user_id' => $client->id, 'registration_token_hash' => null]);
    }

    public function test_another_clients_token_cannot_be_claimed(): void
    {
        $token = str_repeat('b', 64);
        $booking = Reservation::factory()->create(['email' => 'owner@example.com', 'registration_token_hash' => hash('sha256', $token)]);
        $this->actingAs(User::factory()->create(['email' => 'other@example.com']))->postJson('/api/client/reservations/claim', ['registration_token' => $token])->assertNotFound();
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'user_id' => null]);
    }

    public function test_invalid_local_time_and_guest_count_are_rejected(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 27)->setTime(10, 0));
        $client = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $client->id, 'date' => '2027-03-28']);
        $this->actingAs($client)->patchJson('/api/client/reservations/'.$booking->id, ['date' => '2027-03-28', 'time' => '02:30', 'guests' => 11])
            ->assertUnprocessable()->assertJsonValidationErrors(['time', 'guests']);
    }
}
