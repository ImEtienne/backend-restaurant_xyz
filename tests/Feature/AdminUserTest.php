<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('operations')]
    public function test_user_management_requires_admin_rights(string $method, string $suffix): void
    {
        $target = User::factory()->create();
        $url = '/api/admin/users'.str_replace('{id}', (string) $target->id, $suffix);
        $this->json($method, $url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->json($method, $url)->assertForbidden();
        $this->assertModelExists($target);
    }

    public static function operations(): array
    {
        return [['GET', ''], ['POST', ''], ['GET', '/{id}'], ['PATCH', '/{id}'], ['DELETE', '/{id}']];
    }

    public function test_lists_users_with_pagination_without_passwords(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(20)->create();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_shows_one_user_and_returns_404_for_missing_users(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/users/'.$target->id)
            ->assertOk()->assertJsonPath('data.id', $target->id)->assertJsonPath('data.email', $target->email);
        $this->getJson('/api/admin/users/9999')->assertNotFound();
    }

    public function test_creates_an_ordinary_user_with_a_hashed_password(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/api/admin/users', [
            'name' => 'New user', 'email' => 'new@example.com', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123', 'email_verified_at' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('data.is_admin', false)->assertJsonMissingPath('data.password');
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'is_admin' => false, 'email_verified_at' => null]);
        $this->assertTrue(Hash::check('Strong-password-123', User::where('email', 'new@example.com')->firstOrFail()->password));
    }

    public function test_can_create_an_admin_explicitly(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/api/admin/users', [
            'name' => 'Admin', 'email' => 'newadmin@example.com', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123', 'is_admin' => true,
        ])->assertCreated()->assertJsonPath('data.is_admin', true);
        $this->assertDatabaseHas('users', ['email' => 'newadmin@example.com', 'is_admin' => true]);
    }

    public function test_rejects_invalid_creation_and_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/admin/users', ['name' => '', 'email' => $admin->email, 'password' => 'short', 'is_admin' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'is_admin']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_promotes_an_ordinary_user_directly(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/users/'.$target->id, ['is_admin' => true])
            ->assertOk()->assertJsonPath('data.is_admin', true);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_admin' => true]);
    }

    public function test_allows_demotion_when_another_admin_exists(): void
    {
        $target = User::factory()->admin()->create();
        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/users/'.$target->id, ['is_admin' => false])
            ->assertOk()->assertJsonPath('data.is_admin', false);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_admin' => false]);
    }

    public function test_rejects_demotion_of_the_last_admin_without_other_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$admin->id, ['is_admin' => false, 'name' => 'Changed'])->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_admin' => true, 'name' => $admin->name]);
    }

    public function test_updates_identity_and_password_without_setting_verification_from_input(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->patchJson('/api/admin/users/'.$target->id, [
            'name' => 'Updated', 'email' => 'updated@example.com', 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123', 'email_verified_at' => '2026-01-01',
        ])->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => 'updated@example.com', 'email_verified_at' => null]);
        $this->assertTrue(Hash::check('New-password-123', $target->refresh()->password));
    }

    public function test_rejects_duplicate_email_on_update(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$target->id, ['email' => $admin->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => $target->email]);
    }

    public function test_deletes_another_user(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->deleteJson('/api/admin/users/'.$target->id)->assertNoContent();
        $this->assertModelMissing($target);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->deleteJson('/api/admin/users/'.$admin->id)->assertConflict();
        $this->assertModelExists($admin);
    }

    public function test_admin_can_delete_another_admin_when_they_remain_admin(): void
    {
        $target = User::factory()->admin()->create();
        $this->actingAs(User::factory()->admin()->create())->deleteJson('/api/admin/users/'.$target->id)->assertNoContent();
        $this->assertModelMissing($target);
    }
}
