<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_requires_authentication_and_admin_rights(): void
    {
        $this->getJson('/api/admin/search?q=ada')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/search?q=ada')->assertForbidden();
    }

    public function test_searches_both_resources_without_exposing_secrets(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $reservation = Reservation::factory()->create(['name' => 'Ada Client']);
        Reservation::factory()->create(['name' => 'Unrelated', 'email' => 'other@example.com']);
        $this->actingAs(User::factory()->admin()->create(['name' => 'Operator', 'email' => 'operator@example.test']))->getJson('/api/admin/search?q=ADA')
            ->assertOk()->assertJsonCount(1, 'data.users')->assertJsonCount(1, 'data.reservations')
            ->assertJsonPath('data.users.0.id', $user->id)->assertJsonPath('data.reservations.0.id', $reservation->id)
            ->assertJsonMissingPath('data.users.0.password')->assertJsonMissingPath('data.users.0.remember_token');
    }

    public function test_limits_each_type_and_reports_more_results(): void
    {
        User::factory()->count(3)->create(['name' => 'Search match']);
        Reservation::factory()->count(2)->create(['name' => 'Search match']);
        $this->actingAs(User::factory()->admin()->create(['name' => 'Operator', 'email' => 'operator@example.test']))->getJson('/api/admin/search?q=Search&limit=2')
            ->assertOk()->assertJsonCount(2, 'data.users')->assertJsonCount(2, 'data.reservations')
            ->assertJsonPath('meta.has_more.users', true)->assertJsonPath('meta.has_more.reservations', false);
    }

    public function test_treats_like_wildcards_as_literal_characters(): void
    {
        $user = User::factory()->create(['name' => 'Literal %_! marker']);
        User::factory()->create(['name' => 'Other user']);
        $this->actingAs(User::factory()->admin()->create(['name' => 'Operator', 'email' => 'operator@example.test']))->getJson('/api/admin/search?q='.urlencode('%_!'))
            ->assertOk()->assertJsonCount(1, 'data.users')->assertJsonPath('data.users.0.id', $user->id);
    }

    public function test_searches_reservation_phone_date_and_status(): void
    {
        $reservation = Reservation::factory()->create(['phone' => '+33111222333', 'date' => '2027-03-15', 'status' => 'confirmed']);
        $this->actingAs(User::factory()->admin()->create(['name' => 'Operator', 'email' => 'operator@example.test']));
        foreach (['111222333', '2027-03-15', 'confirmed'] as $term) {
            $this->getJson('/api/admin/search?q='.$term)->assertOk()->assertJsonPath('data.reservations.0.id', $reservation->id);
        }
    }

    public function test_finds_exact_identifiers_and_returns_empty_arrays_for_no_matches(): void
    {
        $user = User::factory()->create();
        $this->actingAs(User::factory()->admin()->create(['name' => 'Operator', 'email' => 'operator@example.test']))->getJson('/api/admin/search?q='.urlencode('#'.$user->id))
            ->assertOk()->assertJsonPath('data.users.0.id', $user->id);
        $this->getJson('/api/admin/search?q=unmatchable-token-xyz')->assertOk()->assertJsonPath('data.users', [])->assertJsonPath('data.reservations', []);
    }

    #[DataProvider('invalidQueries')]
    public function test_rejects_invalid_search_parameters(string $query, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create(['name' => 'Operator', 'email' => 'operator@example.test']))->getJson('/api/admin/search?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        return [['', 'q'], ['q=a', 'q'], ['q[]=ada', 'q'], ['q='.str_repeat('a', 101), 'q'], ['q=ada&limit=21', 'limit'], ['q=ada&limit=0', 'limit']];
    }
}
