<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\ReservationAvailabilityService;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Notifications\ReservationActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request, ReservationAvailabilityService $availability): JsonResponse
    {
        $token = Str::random(64);
        $reservation = DB::transaction(function () use ($request, $availability, $token): Reservation {
            $reservation = new Reservation($request->safe()->only(['name', 'email', 'phone', 'date', 'time', 'guests']));
            $reservation->status = 'pending';
            $reservation->registration_token_hash = hash('sha256', $token);
            $availability->assign(
                $reservation,
                $request->validated('date'),
                $request->validated('time'),
                (int) $request->validated('guests'),
                $request->validated('service_key'),
            );
            $reservation->save();
            ReservationActivity::notifyAdmins($reservation, 'created');

            return $reservation;
        }, 3);

        return response()->json([...(new ReservationResource($reservation))->resolve($request), 'registration_token' => $token], 201);
    }
}
