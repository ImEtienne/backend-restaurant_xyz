<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->patchJson('/api/profile', ['name' => 'Changed'])->assertUnauthorized();
    }

    public function test_user_can_login_and_read_their_own_profile(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'is_admin' => true])
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.is_admin', false)->assertJsonMissingPath('data.password');
        Auth::forgetGuards();
        $this->getJson('/api/profile')->assertOk()->assertExactJson(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_admin' => false, 'role' => 'client', 'is_active' => true]]);
    }

    public function test_name_and_password_changes_keep_the_current_session_usable(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        Auth::forgetGuards();
        $this->patchJson('/api/profile', ['password' => 'New-password-123', 'password_confirmation' => 'New-password-123', 'current_password' => 'password'])->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Identifiants incorrects.');
        $this->assertGuest('web');
    }

    public function test_user_can_change_their_name_without_changing_someone_else(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/profile', ['name' => 'New name', 'id' => $other->id])
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.name', 'New name');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New name']);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => $other->name]);
    }

    public function test_user_cannot_promote_themselves_through_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/profile', ['is_admin' => true])->assertUnprocessable()->assertJsonValidationErrors('is_admin');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_admin' => false]);
    }

    public function test_email_change_requires_current_password_and_clears_verification(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/profile', ['email' => 'new@example.com'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->patchJson('/api/profile', ['email' => 'new@example.com', 'current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.com');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new@example.com', 'email_verified_at' => null]);
    }

    public function test_rejects_another_users_email_and_invalid_profile_fields(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/profile', ['email' => $other->email, 'current_password' => 'password', 'name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'name']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'name' => $user->name]);
    }

    public function test_changes_password_only_with_correct_current_password_and_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/profile', ['password' => 'New-password-123', 'password_confirmation' => 'New-password-123', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/profile', ['password' => 'New-password-123', 'password_confirmation' => 'New-password-123', 'current_password' => 'password'])
            ->assertOk()->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('New-password-123', $user->refresh()->password));
    }

    public function test_rejects_short_password_and_mismatched_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/profile', ['password' => 'short', 'password_confirmation' => 'different', 'current_password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_user_can_logout_and_loses_access_to_profile(): void
    {
        $user = User::factory()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->actingAs($user, 'web');
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_profile_update_requires_csrf_for_real_frontend_requests(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->app->instance('env', 'local');
        $this->withHeader('Origin', 'http://localhost:5173')->patchJson('/api/profile', ['name' => 'Changed'])->assertStatus(419);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);
    }
}
