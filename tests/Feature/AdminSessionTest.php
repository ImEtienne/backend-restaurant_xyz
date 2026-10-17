<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_logs_in_an_admin_with_session_regeneration_and_no_secrets_in_json(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $oldSession = session()->getId();

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password', 'is_admin' => false])
            ->assertOk()->assertExactJson(['data' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'is_admin' => true, 'role' => 'admin', 'is_active' => true]]);

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        Auth::forgetGuards();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.id', $admin->id);
    }

    public function test_rejects_incorrect_credentials_with_422(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Identifiants incorrects ou acces administrateur non autorise.');

        $this->assertGuest('web');
    }

    public function test_rejects_non_admin_login_even_with_client_supplied_admin_flag(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'password', 'is_admin' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_admin' => false]);
    }

    public function test_validates_missing_login_fields_with_422(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/admin/login', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_returns_419_for_login_without_a_stateful_frontend_origin(): void
    {
        $this->postJson('/api/admin/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertStatus(419);
        $this->assertGuest('web');
    }

    public function test_returns_429_after_five_login_attempts(): void
    {
        $this->freezeTime();
        $this->withHeader('Origin', 'http://localhost:5173');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/admin/login', [])->assertUnprocessable();
        }

        $this->postJson('/api/admin/login', [])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->postJson('/api/admin/login', [])->assertUnprocessable();
    }

    public function test_returns_401_for_guest_identity_and_logout(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->postJson('/api/admin/logout')->assertUnauthorized();
    }

    public function test_returns_401_for_an_invalid_bearer_token(): void
    {
        $this->withHeader('Authorization', 'Bearer 9999|invalid-token')->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_returns_403_for_non_admin_identity(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/me')->assertForbidden();
    }

    public function test_logs_out_and_invalidates_the_session(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withHeader('Origin', 'http://localhost:5173')->actingAs($admin, 'web');
        $this->withSession(['marker' => 'remove-me']);
        $oldToken = session()->token();

        $this->postJson('/api/admin/logout')->assertNoContent()->assertSessionMissing('marker');

        $this->assertGuest('web');
        $this->assertNotSame($oldToken, session()->token());
        Auth::forgetGuards();
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_rejects_login_without_csrf_token_outside_test_mode(): void
    {
        $this->app->instance('env', 'local');

        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/admin/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertStatus(419);
    }

    public function test_rejects_authenticated_update_without_csrf_token_outside_test_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $reservation = Reservation::factory()->create();
        $this->actingAs($admin, 'web');
        $this->app->instance('env', 'local');

        $this->withHeader('Origin', 'http://localhost:5173')->patchJson('/api/admin/reservations/'.$reservation->id, ['status' => 'confirmed'])->assertStatus(419);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }

    public function test_allows_csrf_initialization_and_credentialed_admin_preflight(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173'])->get('/sanctum/csrf-cookie')
            ->assertNoContent()->assertHeader('Access-Control-Allow-Credentials', 'true')->assertCookie('XSRF-TOKEN');

        $this->withHeaders([
            'Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/admin/reservations/1')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Headers', 'content-type, accept, x-xsrf-token, x-csrf-token');
    }

    public function test_accepts_login_with_a_valid_csrf_token_outside_test_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withSession(['_token' => 'valid-csrf-token']);
        $this->app->instance('env', 'local');

        $this->withHeaders(['Origin' => 'http://localhost:5173', 'X-CSRF-TOKEN' => 'valid-csrf-token'])
            ->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.id', $admin->id);
    }

    public function test_revoked_admin_rights_are_checked_again_on_the_next_request(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $admin->is_admin = false;
        $admin->save();
        Auth::forgetGuards();

        $this->getJson('/api/admin/me')->assertForbidden();
    }

    public function test_has_no_public_registration_or_blade_admin_pages(): void
    {
        $this->postJson('/api/register', ['is_admin' => true])->assertNotFound();
        $this->get('/admin/login')->assertNotFound();
    }
}
