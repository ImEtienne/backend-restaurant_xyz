<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_pending_request_and_returns_201_without_sending_notifications(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        Mail::fake();
        Notification::fake();
        $payload = $this->validPayload();

        $response = $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/reservations', $payload);

        $response->assertCreated()->assertExactJson([
            'id' => $response->json('id'),
            'status' => 'pending',
            'message' => 'Votre demande de reservation a ete recue et reste en attente de confirmation.',
        ]);
        $this->assertIsInt($response->json('id'));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('reservations', [
            ...$payload,
            'id' => $response->json('id'),
            'time' => '19:30',
            'status' => 'pending',
        ]);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_ignores_client_supplied_status_and_identifier(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $response = $this->postJson('/api/reservations', [
            ...$this->validPayload(),
            'status' => 'confirmed',
            'id' => 9999,
        ]);

        $response->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('reservations', ['id' => $response->json('id'), 'status' => 'pending']);
        $this->assertDatabaseMissing('reservations', ['id' => 9999]);
    }

    public function test_returns_422_for_missing_fields_even_without_an_accept_header(): void
    {
        $response = $this->post('/api/reservations', []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'email', 'phone', 'date', 'time', 'guests',
        ])->assertJsonPath('errors.name.0', 'Le champ name est obligatoire.');
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_returns_422_without_persisting_invalid_fields(string $field, mixed $value, string $message): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $response = $this->postJson('/api/reservations', array_replace($this->validPayload(), [$field => $value]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([$field])
            ->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'empty name' => ['name', '   ', 'Le champ name est obligatoire.'],
            'non string name' => ['name', ['Ada'], 'Le champ name doit etre une chaine de caracteres.'],
            'long name' => ['name', str_repeat('a', 101), 'Le champ name ne doit pas depasser 100 caracteres.'],
            'invalid email' => ['email', 'invalid', 'Le champ email doit etre une adresse email valide.'],
            'non string email' => ['email', ['ada@example.com'], 'Le champ email doit etre une chaine de caracteres.'],
            'long email' => ['email', str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 58).'.com', 'Le champ email ne doit pas depasser 254 caracteres.'],
            'short phone' => ['phone', '12345', 'Le champ phone doit contenir au moins 6 caracteres.'],
            'long phone' => ['phone', str_repeat('1', 33), 'Le champ phone ne doit pas depasser 32 caracteres.'],
            'non string phone' => ['phone', 123456, 'Le champ phone doit etre une chaine de caracteres.'],
            'invalid date' => ['date', '2026-02-30', 'Le champ date doit respecter le format Y-m-d.'],
            'wrong date format' => ['date', '16/10/2026', 'Le champ date doit respecter le format Y-m-d.'],
            'past date' => ['date', '2026-10-14', 'La date de reservation ne peut pas etre passee.'],
            'invalid hour' => ['time', '24:00', 'Le champ time doit respecter le format H:i.'],
            'wrong time format' => ['time', '7:30 PM', 'Le champ time doit respecter le format H:i.'],
            'seconds not accepted' => ['time', '19:30:00', 'Le champ time doit respecter le format H:i.'],
            'zero guests' => ['guests', 0, 'Le nombre de personnes doit etre compris entre 1 et 10.'],
            'too many guests' => ['guests', 11, 'Le nombre de personnes doit etre compris entre 1 et 10.'],
            'fractional guests' => ['guests', 2.5, 'Le nombre de personnes doit etre un entier.'],
        ];
    }

    public function test_accepts_field_length_and_guest_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $this->postJson('/api/reservations', [
            ...$this->validPayload(),
            'name' => str_repeat('a', 100),
            'phone' => '123456',
            'guests' => 1,
        ])->assertCreated();
        $this->postJson('/api/reservations', [
            ...$this->validPayload(),
            'phone' => str_repeat('1', 32),
            'guests' => 10,
        ])->assertCreated();

        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_returns_422_for_an_elapsed_time_today_in_paris(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(18, 0));

        $response = $this->postJson('/api/reservations', $this->validPayload());

        $response->assertUnprocessable()
            ->assertJsonPath('errors.time.0', 'La date et l\'heure de reservation doivent etre dans le futur.');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_uses_the_paris_calendar_date_when_utc_is_still_yesterday(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(22, 30));

        $response = $this->postJson('/api/reservations', [
            ...$this->validPayload(),
            'date' => '2026-10-16',
            'time' => '00:45',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('reservations', ['date' => '2026-10-16', 'time' => '00:45']);
    }

    public function test_returns_422_for_a_nonexistent_time_during_daylight_saving_transition(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 28)->setTime(12, 0));

        $response = $this->postJson('/api/reservations', [
            ...$this->validPayload(),
            'date' => '2026-03-29',
            'time' => '02:30',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.time.0', 'Cette heure locale est invalide pour le fuseau du restaurant.');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_returns_429_after_five_attempts_and_allows_requests_after_the_window(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/reservations', [])->assertUnprocessable();
        }

        $response = $this->postJson('/api/reservations', []);

        $response->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('reservations', 0);

        $this->travel(61)->seconds();
        $this->postJson('/api/reservations', [])->assertUnprocessable();
    }

    public function test_allows_json_preflight_from_the_configured_frontend(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,accept',
        ])->options('/api/reservations');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_does_not_allow_cors_for_an_unknown_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/reservations');

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_accepts_public_reservations_without_csrf_even_from_the_frontend(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $this->app->instance('env', 'local');

        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/reservations', $this->validPayload())->assertCreated();

        $this->assertDatabaseCount('reservations', 1);
    }

    private function validPayload(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+33123456789',
            'date' => '2026-10-15',
            'time' => '19:30',
            'guests' => 2,
        ];
    }
}
