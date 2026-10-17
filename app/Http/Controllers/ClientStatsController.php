<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientStatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now(config('reservations.timezone'));
        $row = Reservation::query()->where('user_id', $request->user()->id)->toBase()
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(guests), 0) as total_guests')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as pending', ['pending'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as confirmed', ['confirmed'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as rejected', ['rejected'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as cancelled', ['cancelled'])
            ->selectRaw('COUNT(CASE WHEN status IN (?, ?) AND (date > ? OR (date = ? AND time > ?)) THEN 1 END) as upcoming',
                ['pending', 'confirmed', $now->toDateString(), $now->toDateString(), $now->format('H:i:s')])->first();

        return response()->json(['data' => [
            'total_reservations' => (int) $row->total, 'pending' => (int) $row->pending,
            'confirmed' => (int) $row->confirmed, 'rejected' => (int) $row->rejected,
            'cancelled' => (int) $row->cancelled, 'upcoming' => (int) $row->upcoming,
            'total_guests' => (int) $row->total_guests,
        ], 'meta' => ['timezone' => config('reservations.timezone')]]);
    }
}
