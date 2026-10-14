<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $reservation = new Reservation($request->validated());
        $reservation->status = 'pending';
        $reservation->save();

        return (new ReservationResource($reservation))->response()->setStatusCode(201);
    }
}