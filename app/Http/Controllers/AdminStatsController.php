<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationStatsRequest;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use stdClass;

class AdminStatsController extends Controller
{
    public function summary(ReservationStatsRequest $request): JsonResponse
    {
        $metrics = $this->metrics($this->query($request)->first());
        $total = $metrics['total_reservations'];

        return response()->json([
            'data' => [
                ...$metrics,
                'average_party_size' => $total > 0 ? round($metrics['total_guests'] / $total, 2) : 0.0,
                'confirmation_rate' => $total > 0 ? round($metrics['confirmed'] / $total * 100, 2) : 0.0,
            ],
            'meta' => $this->period($request),
        ]);
    }

    public function daily(ReservationStatsRequest $request): JsonResponse
    {
        $rows = $this->query($request)->addSelect('date')->groupBy('date')->get()->keyBy('date');
        $period = $this->period($request);
        $end = CarbonImmutable::parse($period['end_date']);
        $data = [];

        for ($date = CarbonImmutable::parse($period['start_date']); $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $day = $date->toDateString();
            $data[] = ['date' => $day, ...$this->metrics($rows->get($day))];
        }

        return response()->json(['data' => $data, 'meta' => $period]);
    }

    public function hourly(ReservationStatsRequest $request): JsonResponse
    {
        $rows = $this->query($request)->selectRaw('SUBSTR(time, 1, 2) as hour')
            ->groupByRaw('SUBSTR(time, 1, 2)')->get()->keyBy('hour');
        $data = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $key = sprintf('%02d', $hour);
            $data[] = ['hour' => $key.':00', ...$this->metrics($rows->get($key))];
        }

        return response()->json(['data' => $data, 'meta' => $this->period($request)]);
    }

    private function query(ReservationStatsRequest $request): Builder
    {
        $period = $this->period($request);

        return Reservation::query()->whereBetween('date', [$period['start_date'], $period['end_date']])->toBase()
            ->selectRaw('COUNT(*) as total_reservations, COALESCE(SUM(guests), 0) as total_guests')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as pending', ['pending'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as confirmed', ['confirmed'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as rejected', ['rejected'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as cancelled', ['cancelled'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN guests ELSE 0 END), 0) as confirmed_guests', ['confirmed']);
    }

    /** @return array{total_reservations: int, pending: int, confirmed: int, rejected: int, cancelled: int, total_guests: int, confirmed_guests: int} */
    private function metrics(?stdClass $row): array
    {
        return [
            'total_reservations' => (int) ($row->total_reservations ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'confirmed' => (int) ($row->confirmed ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'total_guests' => (int) ($row->total_guests ?? 0),
            'confirmed_guests' => (int) ($row->confirmed_guests ?? 0),
        ];
    }

    /** @return array{start_date: string, end_date: string, timezone: string, date_basis: string} */
    private function period(ReservationStatsRequest $request): array
    {
        return [
            'start_date' => $request->validated('start_date'),
            'end_date' => $request->validated('end_date'),
            'timezone' => config('reservations.timezone'),
            'date_basis' => 'reservation_date',
        ];
    }
}
