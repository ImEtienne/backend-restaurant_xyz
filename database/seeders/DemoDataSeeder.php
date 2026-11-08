<?php

namespace Database\Seeders;

use App\Models\Reservation;
use App\Models\RestaurantServiceException;
use App\Models\RestaurantServiceSchedule;
use App\Models\User;
use App\Notifications\ClientRegistrationPending;
use App\Notifications\ReservationActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Les donnees de demonstration sont reservees aux environnements local et testing.');
        }

        DB::transaction(function (): void {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->get();
            if ($admins->isEmpty()) {
                throw new RuntimeException('Un administrateur existant est necessaire pour les notifications de demonstration.');
            }

            if (User::query()->where('email', 'like', 'demo-client-%@example.test')->exists()
                || RestaurantServiceSchedule::query()->where('service_key', 'like', 'demo-service-%')->exists()) {
                throw new RuntimeException('Des donnees de demonstration existent deja ; aucune donnee n a ete ajoutee.');
            }

            $today = CarbonImmutable::now(config('reservations.timezone'))->startOfDay();
            $password = Hash::make(Str::random(64));
            $clients = collect();
            for ($i = 0; $i < 150; $i++) {
                $clients->push(User::factory()->create([
                    'email' => sprintf('demo-client-%03d@example.test', $i + 1),
                    'password' => $password,
                    'is_active' => $i % 3 !== 0,
                    'email_verified_at' => null,
                ]));
            }

            $schedules = collect();
            for ($i = 0; $i < 450; $i++) {
                $day = ($i % 7) + 1;
                $serviceNumber = intdiv($i, 7) + 1;
                $schedules->push(RestaurantServiceSchedule::factory()->create([
                    'service_key' => sprintf('demo-service-%03d', $serviceNumber),
                    'service_name' => sprintf('Service de test %03d', $serviceNumber),
                    'day_of_week' => $day,
                ]));
            }

            for ($i = 0; $i < 450; $i++) {
                $schedule = $schedules[$i];
                $date = $today->subDays(451 - $i)->toDateString();
                $closed = $i % 2 === 0;
                RestaurantServiceException::factory()->create([
                    'service_key' => $schedule->service_key,
                    'service_name' => $schedule->service_name,
                    'start_date' => $date,
                    'end_date' => $date,
                    'is_closed' => $closed,
                    'opens_at' => $closed ? null : '18:00',
                    'closes_at' => $closed ? null : '22:00',
                    'slot_interval_minutes' => $closed ? null : 30,
                    'duration_minutes' => $closed ? null : 90,
                    'capacity_guests' => $closed ? null : 40,
                ]);
            }

            $schedulesByDay = $schedules->groupBy('day_of_week');
            $reservations = collect();
            $statuses = ['pending', 'confirmed', 'rejected', 'cancelled'];
            for ($i = 0; $i < 450; $i++) {
                $client = $clients[$i % $clients->count()];
                $date = $today->addDays($i - 225);
                $daySchedules = $schedulesByDay[$date->dayOfWeekIso];
                $schedule = $daySchedules[$i % $daySchedules->count()];
                $reservations->push(Reservation::factory()->create([
                    'user_id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'date' => $date->toDateString(),
                    'time' => '19:30',
                    'guests' => ($i % 10) + 1,
                    'status' => $statuses[$i % count($statuses)],
                    'service_key' => $schedule->service_key,
                    'duration_minutes' => $schedule->duration_minutes,
                ]));
            }

            $rows = [];
            $activities = ['created', 'updated', 'cancelled'];
            for ($i = 0; $i < 450; $i++) {
                $admin = $admins[$i % $admins->count()];
                $reservationIndex = $i % 4 === 1 ? $i - 1 : ($i % 4 === 2 ? $i + 1 : $i);
                $notification = $i % 4 === 3
                    ? new ClientRegistrationPending($clients[($i % 50) * 3])
                    : new ReservationActivity($reservations[$reservationIndex], $activities[$i % 4]);
                $timestamp = $today->subDays($i % 30)->addMinutes($i % 1440);
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'type' => $notification::class,
                    'notifiable_type' => $admin->getMorphClass(),
                    'notifiable_id' => $admin->id,
                    'data' => json_encode($notification->toArray($admin), JSON_THROW_ON_ERROR),
                    'read_at' => $i % 2 === 0 ? $timestamp : null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }
        });
    }
}
