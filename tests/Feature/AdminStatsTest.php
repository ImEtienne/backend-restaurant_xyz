<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_returns_401_for_guests_and_403_for_non_admins(string $endpoint): void
    {
        $this->get($endpoint)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($endpoint)->assertForbidden();
    }

    public static function endpoints(): array
    {
        return ['summary' => ['/api/admin/stats'], 'daily' => ['/api/admin/stats/daily'], 'hourly' => ['/api/admin/stats/hourly']];
    }

    public function test_summarizes_the_inclusive_reservation_date_range_without_personal_data(): void
    {
        Reservation::factory()->create(['date' => '2026-10-01', 'guests' => 2, 'status' => 'pending', 'created_at' => '2026-09-01 12:00:00']);
        Reservation::factory()->create(['date' => '2026-10-02', 'guests' => 4, 'status' => 'confirmed']);
        Reservation::factory()->create(['date' => '2026-10-03', 'guests' => 3, 'status' => 'rejected']);
        Reservation::factory()->create(['date' => '2026-09-30', 'guests' => 10, 'status' => 'confirmed']);
        Reservation::factory()->create(['date' => '2026-10-04', 'guests' => 10, 'status' => 'confirmed']);

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats?start_date=2026-10-01&end_date=2026-10-03');

        $response->assertOk()->assertExactJson([
            'data' => ['total_reservations' => 3, 'pending' => 1, 'confirmed' => 1, 'rejected' => 1, 'cancelled' => 0,
                'total_guests' => 9, 'confirmed_guests' => 4, 'average_party_size' => 3, 'confirmation_rate' => 33.33],
            'meta' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-03', 'timezone' => 'Europe/Paris', 'date_basis' => 'reservation_date'],
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_returns_zero_metrics_for_an_empty_period(): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats?start_date=2026-10-01&end_date=2026-10-01')
            ->assertOk()->assertJsonPath('data.total_reservations', 0)->assertJsonPath('data.total_guests', 0)
            ->assertJsonPath('data.confirmed_guests', 0)->assertJsonPath('data.confirmation_rate', 0)
            ->assertJsonPath('data.average_party_size', 0);
    }

    public function test_defaults_to_thirty_days_using_the_restaurant_calendar_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 22:30:00', 'UTC'));
        Reservation::factory()->create(['date' => '2026-10-16', 'guests' => 2]);
        Reservation::factory()->create(['date' => '2026-10-17', 'guests' => 2]);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats')
            ->assertOk()->assertJsonPath('meta.start_date', '2026-09-17')->assertJsonPath('meta.end_date', '2026-10-16')
            ->assertJsonPath('data.total_reservations', 1);
    }

    public function test_fills_missing_dates_and_returns_daily_counts_in_order(): void
    {
        Reservation::factory()->create(['date' => '2026-10-01', 'guests' => 4, 'status' => 'confirmed']);
        Reservation::factory()->create(['date' => '2026-10-01', 'guests' => 2, 'status' => 'pending']);
        Reservation::factory()->create(['date' => '2026-10-03', 'guests' => 3, 'status' => 'rejected']);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats/daily?start_date=2026-10-01&end_date=2026-10-03')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.date', '2026-10-01')
            ->assertJsonPath('data.0.total_reservations', 2)->assertJsonPath('data.0.total_guests', 6)
            ->assertJsonPath('data.0.confirmed_guests', 4)->assertJsonPath('data.1.date', '2026-10-02')
            ->assertJsonPath('data.1.total_reservations', 0)->assertJsonPath('data.1.confirmed', 0)
            ->assertJsonPath('data.2.date', '2026-10-03')->assertJsonPath('data.2.rejected', 1);
    }

    public function test_groups_times_into_twenty_four_hourly_buckets_with_zeroes(): void
    {
        Reservation::factory()->create(['date' => '2026-10-01', 'time' => '19:00', 'guests' => 2, 'status' => 'confirmed']);
        Reservation::factory()->create(['date' => '2026-10-02', 'time' => '19:45:00', 'guests' => 4, 'status' => 'pending']);
        Reservation::factory()->create(['date' => '2026-10-02', 'time' => '09:30', 'guests' => 3, 'status' => 'rejected']);
        Reservation::factory()->create(['date' => '2026-10-03', 'time' => '19:30', 'guests' => 10, 'status' => 'confirmed']);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats/hourly?start_date=2026-10-01&end_date=2026-10-02')
            ->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('data.0.hour', '00:00')->assertJsonPath('data.0.total_reservations', 0)
            ->assertJsonPath('data.9.hour', '09:00')->assertJsonPath('data.9.rejected', 1)
            ->assertJsonPath('data.19.hour', '19:00')->assertJsonPath('data.19.total_reservations', 2)
            ->assertJsonPath('data.19.total_guests', 6)->assertJsonPath('data.19.confirmed_guests', 2)
            ->assertJsonPath('data.23.hour', '23:00');
    }

    #[DataProvider('invalidPeriods')]
    public function test_returns_422_for_invalid_or_excessive_periods(string $query, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats?'.$query)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidPeriods(): array
    {
        return [
            'invalid date' => ['start_date=2026-02-30&end_date=2026-03-01', 'start_date'],
            'wrong format' => ['start_date=01-10-2026&end_date=2026-10-03', 'start_date'],
            'invalid end' => ['start_date=2026-10-01&end_date=invalid', 'end_date'],
            'reversed' => ['start_date=2026-10-03&end_date=2026-10-01', 'end_date'],
            'too long' => ['start_date=2026-01-01&end_date=2027-01-02', 'end_date'],
            'empty start' => ['start_date=&end_date=2026-10-01', 'start_date'],
            'array input' => ['start_date[]=2026-10-01&end_date=2026-10-03', 'start_date'],
        ];
    }

    public function test_accepts_the_maximum_inclusive_period(): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/stats/daily?start_date=2026-01-01&end_date=2027-01-01')
            ->assertOk()->assertJsonCount(366, 'data');
    }
}
