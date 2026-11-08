<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\RestaurantServiceException;
use App\Models\RestaurantServiceSchedule;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeder_creates_complete_demo_data_without_overwriting_existing_data(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $existing = Reservation::factory()->create(['name' => 'Existing booking']);

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(150, User::query()->where('email', 'like', 'demo-client-%@example.test')->count());
        $this->assertSame(50, User::query()->where('email', 'like', 'demo-client-%@example.test')->where('is_active', false)->count());
        $this->assertSame(450, Reservation::query()->where('email', 'like', 'demo-client-%@example.test')->count());
        $this->assertSame(450, RestaurantServiceSchedule::query()->where('service_key', 'like', 'demo-service-%')->count());
        $this->assertSame(450, RestaurantServiceException::query()->where('service_key', 'like', 'demo-service-%')->count());
        $this->assertSame(0, RestaurantServiceException::query()->where('service_key', 'like', 'demo-service-%')
            ->where('is_closed', true)->whereNotNull('opens_at')->count());
        $this->assertDatabaseCount('notifications', 450);
        $this->assertDatabaseHas('reservations', ['id' => $existing->id, 'name' => 'Existing booking']);
        $this->assertSame(225, $admin->notifications()->count());
        $this->assertSame(225, $otherAdmin->notifications()->count());
        $this->assertSame(4, DB::table('notifications')->get()->map(fn ($row) => json_decode($row->data, true)['type'])->unique()->count());
        $this->actingAs($admin)->getJson('/api/admin/notifications')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data' => [['id', 'type', 'message', 'name', 'read_at']]]);
        $this->assertSame(4, Reservation::query()->where('email', 'like', 'demo-client-%@example.test')->distinct()->count('status'));
        $this->assertSame(0, Reservation::query()->where('email', 'like', 'demo-client-%@example.test')->whereNull('user_id')->count());

        try {
            $this->seed(DemoDataSeeder::class);
            $this->fail('A second run must refuse duplicate demo data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existent deja', $exception->getMessage());
        }
        $this->assertDatabaseCount('notifications', 450);
        $this->assertDatabaseCount('reservations', 451);
    }

    public function test_seeder_requires_an_existing_administrator_without_writing_data(): void
    {
        try {
            $this->seed(DemoDataSeeder::class);
            $this->fail('An existing administrator is required.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('administrateur existant', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_seeder_refuses_production_even_if_an_admin_exists(): void
    {
        User::factory()->admin()->create();
        $this->app['env'] = 'production';

        try {
            $this->app->make(DemoDataSeeder::class)->run();
            $this->fail('The seeder must refuse production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('environnements local et testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('notifications', 0);
    }
}
