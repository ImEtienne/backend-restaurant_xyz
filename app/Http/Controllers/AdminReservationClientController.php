<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminReservationResource;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReservationClientController extends Controller
{
    public function __invoke(Request $request, Reservation $reservation): AdminReservationResource
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $client = User::query()->findOrFail($data['user_id']);
        abort_if($client->is_admin, 422, 'La reservation doit etre rattachee a un client.');

        return new AdminReservationResource(DB::transaction(function () use ($reservation, $client): Reservation {
            $owned = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            abort_if($owned->user_id !== null && (int) $owned->user_id !== $client->id, 409, 'Cette reservation appartient deja a un autre client.');
            $owned->user_id = $client->id;
            $owned->registration_token_hash = null;
            $owned->save();

            return $owned;
        }, 3));
    }
}
