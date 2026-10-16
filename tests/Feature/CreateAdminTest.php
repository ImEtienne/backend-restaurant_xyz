<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com', '--name' => 'Restaurant'])
            ->expectsQuestion('Mot de passe (12 caracteres minimum)', 'Strong-password-123')
            ->expectsQuestion('Confirmez le mot de passe', 'Strong-password-123')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Restaurant', 'is_admin' => true]);
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Strong-password-123', $user->password));
    }

    public function test_refuses_a_short_password_without_creating_an_account(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com'])
            ->expectsQuestion('Mot de passe (12 caracteres minimum)', 'short')
            ->expectsQuestion('Confirmez le mot de passe', 'short')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_to_promote_an_existing_account_or_change_its_password(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $oldPassword = $user->password;

        $this->artisan('admin:create', ['email' => 'existing@example.com'])
            ->expectsQuestion('Mot de passe (12 caracteres minimum)', 'Strong-password-123')
            ->expectsQuestion('Confirmez le mot de passe', 'Strong-password-123')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_admin' => false, 'password' => $oldPassword]);
    }

    public function test_refuses_non_interactive_creation_without_a_password(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
