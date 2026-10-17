<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterClientRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientRegistrationController extends Controller
{
    public function __invoke(RegisterClientRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $reservation = Reservation::query()->where('registration_token_hash', hash('sha256', $request->validated('registration_token')))
                ->whereNull('user_id')->where('created_at', '>=', now()->subDays(7))->lockForUpdate()->first();
            if (! $reservation || strcasecmp($reservation->email, $request->validated('email')) !== 0) {
                throw ValidationException::withMessages(['registration_token' => 'La cle de reservation est invalide, expiree ou ne correspond pas a cet email.']);
            }
            $user = new User(['name' => $reservation->name, 'email' => $request->validated('email'), 'password' => $request->validated('password')]);
            $user->is_admin = false;
            $user->is_active = false;
            $user->save();
            $reservation->user_id = $user->id;
            $reservation->registration_token_hash = null;
            $reservation->save();
        }, 3);

        return response()->json([
            'code' => 'ACCOUNT_PENDING_APPROVAL',
            'message' => "Votre profil n'est pas encore active. Un administrateur doit le valider. Merci de patienter avant de pouvoir utiliser les services.",
        ], 201);
    }
}
