<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_booking_can_create_an_inactive_client_without_verifying_email(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(10, 0));
        $booking = $this->postJson('/api/reservations', ['name' => 'Client', 'email' => 'client@example.com', 'phone' => '0123456789',
            'date' => '2026-10-18', 'time' => '19:30', 'guests' => 2])->assertCreated();
        $token = $booking->json('registration_token');
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('reservations', ['id' => $booking->json('id'), 'registration_token_hash' => hash('sha256', $token)]);
        $this->postJson('/api/client/register', ['email' => 'client@example.com', 'password' => 'Client-password-123',
            'password_confirmation' => 'Client-password-123', 'registration_token' => $token])
            ->assertCreated()->assertJsonPath('code', 'ACCOUNT_PENDING_APPROVAL');
        $user = User::where('email', 'client@example.com')->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Client-password-123', $user->password));
        $this->assertDatabaseHas('reservations', ['id' => $booking->json('id'), 'user_id' => $user->id, 'registration_token_hash' => null]);
        $this->assertGuest('web');
    }

    public function test_invalid_expired_or_mismatched_claim_does_not_create_an_account(): void
    {
        $token = str_repeat('a', 64);
        Reservation::factory()->create(['registration_token_hash' => hash('sha256', $token), 'email' => 'owner@example.com', 'created_at' => now()->subDays(8)]);
        $this->postJson('/api/client/register', ['email' => 'owner@example.com', 'password' => 'Client-password-123', 'password_confirmation' => 'Client-password-123', 'registration_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cannot_register_with_someone_elses_email_or_inject_admin_rights(): void
    {
        $token = str_repeat('a', 64);
        Reservation::factory()->create(['registration_token_hash' => hash('sha256', $token), 'email' => 'owner@example.com']);
        $payload = ['email' => 'attacker@example.com', 'password' => 'Client-password-123', 'password_confirmation' => 'Client-password-123', 'registration_token' => $token];
        $this->postJson('/api/client/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('registration_token');
        $this->postJson('/api/client/register', [...$payload, 'email' => 'owner@example.com', 'is_active' => true, 'is_admin' => true, 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_admin', 'is_active', 'role']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_pending_client_cannot_login_and_receives_toast_message(): void
    {
        $user = User::factory()->pending()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING_APPROVAL')
            ->assertJsonPath('message', "Votre profil n'est pas encore active. Un administrateur doit le valider. Merci de patienter avant de pouvoir utiliser les services.");
        $this->assertGuest('web');
    }

    public function test_inactive_clients_cannot_bypass_activation_with_an_existing_session(): void
    {
        $user = User::factory()->pending()->create();
        $this->actingAs($user)->getJson('/api/profile')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING_APPROVAL');
        $this->getJson('/api/client/reservations')->assertForbidden();
        $this->getJson('/api/client/stats')->assertForbidden();
    }

    public function test_admin_can_activate_and_deactivate_a_client(): void
    {
        $user = User::factory()->pending()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$user->id.'/activation', ['is_active' => true])
            ->assertOk()->assertJsonPath('data.role', 'client')->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$user->id.'/activation', ['is_active' => false])->assertOk();
        $this->actingAs($user->refresh())->getJson('/api/client/stats')->assertForbidden();
    }

    public function test_clients_cannot_activate_themselves_or_other_clients(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/admin/users/'.$user->id.'/activation', ['is_active' => true])->assertForbidden();
        $this->patchJson('/api/profile', ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_administrators_bypass_client_activation_but_cannot_use_client_routes(): void
    {
        $admin = User::factory()->admin()->pending()->create();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/client/reservations')->assertForbidden();
        $this->patchJson('/api/admin/users/'.$admin->id.'/activation', ['is_active' => false])->assertUnprocessable();
    }

    public function test_admin_can_list_clients_waiting_for_activation(): void
    {
        $pending = User::factory()->pending()->create();
        User::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/users?is_active=0')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_an_admin_cannot_silently_move_a_booking_owned_by_another_client(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $booking = Reservation::factory()->create(['user_id' => $owner->id]);
        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/reservations/'.$booking->id.'/client', ['user_id' => $other->id])->assertConflict();
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'user_id' => $owner->id]);
    }

    public function test_admin_can_attach_a_legacy_booking_to_a_client_without_changing_its_status(): void
    {
        $client = User::factory()->pending()->create();
        $booking = Reservation::factory()->create(['status' => 'confirmed']);
        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/reservations/'.$booking->id.'/client', ['user_id' => $client->id])->assertOk();
        $this->assertDatabaseHas('reservations', ['id' => $booking->id, 'user_id' => $client->id, 'status' => 'confirmed']);
    }
}
