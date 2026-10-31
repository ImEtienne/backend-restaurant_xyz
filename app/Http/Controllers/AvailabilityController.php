<?php

namespace App\Http\Controllers;

use App\Http\ReservationAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __invoke(Request $request, ReservationAvailabilityService $availability): JsonResponse
    {
        $today = CarbonImmutable::now(config('reservations.timezone'))->toDateString();
        $filters = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'guests' => ['sometimes', 'required', 'integer', 'between:1,10'],
        ]);
        $slots = $availability->slots($filters['date']);

        if (isset($filters['guests'])) {
            $slots = array_values(array_filter(
                $slots,
                fn (array $slot): bool => $slot['remaining_capacity'] >= (int) $filters['guests'],
            ));
        }

        return response()->json([
            'data' => $slots,
            'meta' => [
                'date' => $filters['date'],
                'timezone' => config('reservations.timezone'),
            ],
        ]);
    }
}
