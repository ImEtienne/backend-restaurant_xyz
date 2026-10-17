<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $reservation = new Reservation($request->validated());
        $reservation->status = 'pending';
        $token = Str::random(64);
        $reservation->registration_token_hash = hash('sha256', $token);
        $reservation->save();

        return response()->json([...(new ReservationResource($reservation))->resolve($request), 'registration_token' => $token], 201);
    }
}
