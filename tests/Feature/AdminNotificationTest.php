<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\RestaurantServiceSchedule;
use App\Models\User;
use App\Notifications\ReservationActivity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_client_registration_notifies_each_admin_of_the_pending_account(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $token = str_repeat('a', 64);
        $reservation = Reservation::factory()->create([
            'name' => 'Marie Dupont',
            'email' => 'marie@example.com',
            'registration_token_hash' => hash('sha256', $token),
        ]);

        $this->postJson('/api/client/register', [
            'email' => 'marie@example.com',
            'password' => 'Client-password-123',
            'password_confirmation' => 'Client-password-123',
            'registration_token' => $token,
        ])->assertCreated()->assertJsonPath('code', 'ACCOUNT_PENDING_APPROVAL');

        $client = User::query()->where('email', 'marie@example.com')->firstOrFail();
        $this->assertFalse($client->is_active);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'user_id' => $client->id, 'registration_token_hash' => null]);
        $this->assertDatabaseCount('notifications', 2);
        $response = $this->actingAs($admin)->getJson('/api/admin/notifications');
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.type', 'client_registered')
            ->assertJsonPath('data.0.user_id', $client->id)
            ->assertJsonPath('data.0.name', 'Marie Dupont')
            ->assertJsonPath('data.0.message', 'Compte client en attente de validation : Marie Dupont')
            ->assertJsonPath('data.0.read_at', null);
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());
        $this->assertSame(0, $client->notifications()->count());
        $this->patchJson('/api/admin/notifications/'.$response->json('data.0.id').'/read')->assertOk();
        $this->assertSame(0, $admin->unreadNotifications()->count());
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());
    }

    public function test_invalid_client_registration_creates_no_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $token = str_repeat('a', 64);
        Reservation::factory()->create([
            'email' => 'owner@example.com',
            'registration_token_hash' => hash('sha256', $token),
        ]);

        $this->postJson('/api/client/register', [
            'email' => 'another@example.com',
            'password' => 'Client-password-123',
            'password_confirmation' => 'Client-password-123',
            'registration_token' => $token,
        ])->assertUnprocessable()->assertJsonValidationErrors('registration_token');

        $this->assertSame(0, $admin->notifications()->count());
        $this->assertDatabaseMissing('users', ['email' => 'another@example.com']);
    }

    public function test_registration_notification_failure_rolls_back_the_account_and_all_notifications(): void
    {
        User::factory()->admin()->create();
        User::factory()->admin()->create();
        $token = str_repeat('a', 64);
        $hash = hash('sha256', $token);
        $reservation = Reservation::factory()->create(['email' => 'marie@example.com', 'registration_token_hash' => $hash]);
        $attempts = 0;
        Event::listen(NotificationSending::class, function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 2) {
                throw new RuntimeException('Notification storage failed.');
            }
        });

        $this->postJson('/api/client/register', [
            'email' => 'marie@example.com',
            'password' => 'Client-password-123',
            'password_confirmation' => 'Client-password-123',
            'registration_token' => $token,
        ])->assertInternalServerError();

        $this->assertSame(2, $attempts);
        $this->assertDatabaseMissing('users', ['email' => 'marie@example.com']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'user_id' => null, 'registration_token_hash' => $hash]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_public_reservation_notifies_each_existing_admin(): void
    {
        $this->freezeTime();
        $this->configureService();
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $response = $this->postJson('/api/reservations', $this->reservationPayload());

        $response->assertCreated();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(0, $client->notifications()->count());
        $this->actingAs($admin)->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.type', 'reservation_created')
            ->assertJsonPath('data.0.reservation_id', $response->json('id'))
            ->assertJsonPath('data.0.name', 'Marie Dupont')
            ->assertJsonPath('data.0.guests', 4)
            ->assertJsonPath('data.0.read_at', null);
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());
    }

    public function test_client_creation_modification_and_cancellation_create_notifications(): void
    {
        $this->freezeTime();
        $this->configureService();
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $payload = $this->reservationPayload();
        unset($payload['email']);

        $response = $this->actingAs($client)->postJson('/api/client/reservations', $payload);
        $response->assertCreated();
        $id = $response->json('data.id');
        $this->patchJson('/api/client/reservations/'.$id, ['guests' => 5])->assertOk();
        $this->postJson('/api/client/reservations/'.$id.'/cancel')->assertOk();

        $data = $admin->notifications()->get()->pluck('data')->keyBy('type');
        $this->assertCount(3, $data);
        $this->assertSame(4, $data['reservation_created']['guests']);
        $this->assertSame(5, $data['reservation_updated']['guests']);
        $this->assertSame(5, $data['reservation_cancelled']['guests']);
        $this->assertSame($id, $data['reservation_cancelled']['reservation_id']);
    }

    public function test_unchanged_or_rejected_client_operations_do_not_create_notifications(): void
    {
        $this->freezeTime();
        $this->configureService();
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $reservation = Reservation::factory()->create([
            'user_id' => $client->id,
            'date' => now(config('reservations.timezone'))->addDay()->toDateString(),
            'time' => '19:30',
            'guests' => 4,
            'service_key' => 'default',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($client)->patchJson('/api/client/reservations/'.$reservation->id, ['guests' => 4])->assertOk();
        $this->patchJson('/api/client/reservations/'.$reservation->id, ['guests' => 11])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson('/api/client/reservations/'.$reservation->id.'/cancel')->assertNotFound();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_notification_failure_rolls_back_the_reservation_and_other_notifications(): void
    {
        $this->freezeTime();
        $this->configureService();
        User::factory()->admin()->create();
        User::factory()->admin()->create();
        $attempts = 0;
        Event::listen(NotificationSending::class, function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 2) {
                throw new RuntimeException('Notification storage failed.');
            }
        });

        $this->postJson('/api/reservations', $this->reservationPayload())->assertInternalServerError();

        $this->assertSame(2, $attempts);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_new_administrators_do_not_receive_past_notifications(): void
    {
        $this->freezeTime();
        $this->configureService();
        User::factory()->admin()->create();
        $this->postJson('/api/reservations', $this->reservationPayload())->assertCreated();
        $newAdmin = User::factory()->admin()->create();

        $this->actingAs($newAdmin)->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
    }

    public function test_reading_a_notification_is_idempotent_and_isolated_between_admins(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $reservation = Reservation::factory()->create();
        $admin->notify(new ReservationActivity($reservation, 'created'));
        $otherAdmin->notify(new ReservationActivity($reservation, 'created'));
        $own = $admin->notifications()->firstOrFail();
        $other = $otherAdmin->notifications()->firstOrFail();

        $this->actingAs($admin)->patchJson('/api/admin/notifications/'.$other->id.'/read')->assertNotFound();
        $this->patchJson('/api/admin/notifications/'.$own->id.'/read')->assertOk()->assertJsonPath('data.id', $own->id);
        $readAt = $own->fresh()->read_at;
        $this->assertNotNull($readAt);
        $this->travel(1)->minute();
        $this->patchJson('/api/admin/notifications/'.$own->id.'/read')->assertOk();
        $this->assertSame($readAt->toISOString(), $own->fresh()->read_at->toISOString());
        $this->getJson('/api/admin/notifications')->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());
    }

    public function test_mark_all_read_affects_only_the_current_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $reservation = Reservation::factory()->create();
        $admin->notify(new ReservationActivity($reservation, 'created'));
        $admin->notify(new ReservationActivity($reservation, 'updated'));
        $otherAdmin->notify(new ReservationActivity($reservation, 'created'));

        $this->actingAs($admin)->patchJson('/api/admin/notifications/read-all')
            ->assertOk()->assertExactJson(['unread_count' => 0]);

        $this->assertSame(0, $admin->unreadNotifications()->count());
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());
    }

    public function test_notifications_are_paginated_and_unread_count_covers_all_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $reservation = Reservation::factory()->create();
        foreach (range(1, 21) as $index) {
            $admin->notify(new ReservationActivity($reservation, 'created'));
        }

        $this->actingAs($admin)->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.unread_count', 21)
            ->assertJsonStructure(['data' => [['id', 'type', 'message', 'reservation_id', 'name', 'date', 'time', 'guests', 'created_at', 'read_at']], 'links', 'meta']);
        $this->getJson('/api/admin/notifications?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_notifications_require_an_administrator(): void
    {
        $this->getJson('/api/admin/notifications')->assertUnauthorized();
        $this->patchJson('/api/admin/notifications/read-all')->assertUnauthorized();
        $this->patchJson('/api/admin/notifications/unknown/read')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/notifications')->assertForbidden();
        $this->patchJson('/api/admin/notifications/read-all')->assertForbidden();
        $this->patchJson('/api/admin/notifications/unknown/read')->assertForbidden();
    }

    private function configureService(): void
    {
        RestaurantServiceSchedule::query()->create([
            'service_key' => 'default',
            'service_name' => 'Service',
            'day_of_week' => now(config('reservations.timezone'))->addDay()->dayOfWeekIso,
            'opens_at' => '00:00',
            'closes_at' => '23:59',
            'slot_interval_minutes' => 5,
            'duration_minutes' => 60,
            'capacity_guests' => 500,
        ]);
    }

    /** @return array{name: string, email: string, phone: string, date: string, time: string, guests: int} */
    private function reservationPayload(): array
    {
        return [
            'name' => 'Marie Dupont',
            'email' => 'marie@example.com',
            'phone' => '0123456789',
            'date' => now(config('reservations.timezone'))->addDay()->toDateString(),
            'time' => '19:30',
            'guests' => 4,
        ];
    }
}
