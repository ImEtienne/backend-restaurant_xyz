<?php

namespace App\Http;

use App\Models\Reservation;
use App\Models\RestaurantBookingLock;
use App\Models\RestaurantServiceException;
use App\Models\RestaurantServiceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReservationAvailabilityService
{
    /**
     * @return array<int, array{service_key: string, service_name: string, time: string, duration_minutes: int, capacity_guests: int, remaining_capacity: int}>
     */
    public function slots(string $date, ?int $excludeReservationId = null): array
    {
        $timezone = config('reservations.timezone');
        $day = CarbonImmutable::parse($date, $timezone);
        $schedules = RestaurantServiceSchedule::query()
            ->where('day_of_week', $day->dayOfWeekIso)
            ->get()
            ->keyBy('service_key');
        $exceptions = RestaurantServiceException::query()
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->orderBy('id')
            ->get();

        if ($exceptions->contains(fn (RestaurantServiceException $exception): bool => $exception->service_key === '*' && $exception->is_closed)) {
            return [];
        }

        $services = $schedules->map(fn (RestaurantServiceSchedule $schedule): array => [
            'service_key' => $schedule->service_key,
            'service_name' => $schedule->service_name,
            'opens_at' => $schedule->opens_at,
            'closes_at' => $schedule->closes_at,
            'slot_interval_minutes' => $schedule->slot_interval_minutes,
            'duration_minutes' => $schedule->duration_minutes,
            'capacity_guests' => $schedule->capacity_guests,
        ]);

        foreach ($exceptions as $exception) {
            if ($exception->service_key === '*') {
                continue;
            }

            if ($exception->is_closed) {
                $services->forget($exception->service_key);

                continue;
            }

            $services->put($exception->service_key, [
                'service_key' => $exception->service_key,
                'service_name' => $exception->service_name,
                'opens_at' => $exception->opens_at,
                'closes_at' => $exception->closes_at,
                'slot_interval_minutes' => $exception->slot_interval_minutes,
                'duration_minutes' => $exception->duration_minutes,
                'capacity_guests' => $exception->capacity_guests,
            ]);
        }

        $bookings = Reservation::query()
            ->where('date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->when($excludeReservationId !== null, fn ($query) => $query->where('id', '!=', $excludeReservationId))
            ->get(['time', 'guests', 'duration_minutes']);
        $now = CarbonImmutable::now($timezone);
        $slots = [];

        foreach ($services as $service) {
            $opensAt = $this->minutes($service['opens_at']);
            $closesAt = $this->minutes($service['closes_at']);
            $duration = (int) $service['duration_minutes'];
            $interval = (int) $service['slot_interval_minutes'];
            $capacity = (int) $service['capacity_guests'];

            for ($start = $opensAt; $start + $duration <= $closesAt; $start += $interval) {
                $time = sprintf('%02d:%02d', intdiv($start, 60), $start % 60);
                if ($day->isSameDay($now) && $start <= ($now->hour * 60) + $now->minute) {
                    continue;
                }

                $bookedGuests = $bookings->sum(function (Reservation $booking) use ($start, $duration): int {
                    $bookingStart = $this->minutes($booking->time);
                    $bookingDuration = $booking->duration_minutes ?? $duration;

                    return $bookingStart < $start + $duration && $bookingStart + $bookingDuration > $start
                        ? (int) $booking->guests
                        : 0;
                });
                $slots[] = [
                    'service_key' => $service['service_key'],
                    'service_name' => $service['service_name'],
                    'time' => $time,
                    'duration_minutes' => $duration,
                    'capacity_guests' => $capacity,
                    'remaining_capacity' => max(0, $capacity - $bookedGuests),
                ];
            }
        }

        usort($slots, fn (array $left, array $right): int => [$left['time'], $left['service_key']] <=> [$right['time'], $right['service_key']]);

        return $slots;
    }

    public function assign(
        Reservation $reservation,
        string $date,
        string $time,
        int $guests,
        ?string $serviceKey = null,
        ?int $excludeReservationId = null,
    ): void {
        RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);

        $matchingSlots = array_values(array_filter(
            $this->slots($date, $excludeReservationId),
            fn (array $slot): bool => $slot['time'] === substr($time, 0, 5)
                && ($serviceKey === null || $slot['service_key'] === $serviceKey),
        ));

        if (count($matchingSlots) > 1) {
            throw ValidationException::withMessages([
                'service_key' => 'Selectionnez le service correspondant a ce creneau.',
            ]);
        }

        $slot = $matchingSlots[0] ?? null;
        if ($slot === null) {
            throw ValidationException::withMessages([
                'time' => "Ce creneau n'est pas ouvert a la reservation.",
            ]);
        }

        if ($slot['remaining_capacity'] < $guests) {
            throw ValidationException::withMessages([
                'guests' => 'La capacite restante de ce creneau est insuffisante.',
            ]);
        }

        $reservation->service_key = $slot['service_key'];
        $reservation->duration_minutes = $slot['duration_minutes'];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hours * 60) + $minutes;
    }
}
