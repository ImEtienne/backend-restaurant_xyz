<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientReservationRequest;
use App\Http\Requests\UpdateClientReservationRequest;
use App\Http\ReservationAvailabilityService;
use App\Http\Resources\AdminReservationResource;
use App\Models\Reservation;
use App\Notifications\ReservationActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ClientReservationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AdminReservationResource::collection(Reservation::query()->where('user_id', $request->user()->id)
            ->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')->paginate(20));
    }

    public function show(Request $request, int $reservation): AdminReservationResource
    {
        return new AdminReservationResource(Reservation::query()->where('user_id', $request->user()->id)->findOrFail($reservation));
    }

    public function store(StoreClientReservationRequest $request, ReservationAvailabilityService $availability): AdminReservationResource
    {
        $reservation = DB::transaction(function () use ($request, $availability): Reservation {
            $reservation = new Reservation($request->safe()->only(['name', 'phone', 'date', 'time', 'guests']));
            $reservation->email = $request->user()->email;
            $reservation->user_id = $request->user()->id;
            $reservation->status = 'pending';
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

        return new AdminReservationResource($reservation);
    }

    public function update(UpdateClientReservationRequest $request, int $reservation, ReservationAvailabilityService $availability): AdminReservationResource
    {
        return new AdminReservationResource(DB::transaction(function () use ($request, $reservation, $availability): Reservation {
            $owned = Reservation::query()->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($reservation);
            $this->ensureEditable($owned);
            $owned->fill($request->safe()->only(['name', 'phone', 'date', 'time', 'guests']));
            $availability->assign(
                $owned,
                $request->validated('date'),
                $request->validated('time'),
                (int) $request->validated('guests'),
                $request->validated('service_key'),
                $owned->id,
            );
            $changed = $owned->isDirty();
            $owned->save();
            if ($changed) {
                ReservationActivity::notifyAdmins($owned, 'updated');
            }

            return $owned;
        }, 3));
    }

    public function cancel(Request $request, int $reservation): AdminReservationResource
    {
        return new AdminReservationResource(DB::transaction(function () use ($request, $reservation): Reservation {
            $owned = Reservation::query()->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($reservation);
            $this->ensureEditable($owned);
            $owned->status = 'cancelled';
            $owned->save();
            ReservationActivity::notifyAdmins($owned, 'cancelled');

            return $owned;
        }, 3));
    }

    public function claim(Request $request): AdminReservationResource
    {
        $data = $request->validate(['registration_token' => ['required', 'string', 'size:64']]);

        return new AdminReservationResource(DB::transaction(function () use ($request, $data): Reservation {
            $reservation = Reservation::query()->where('registration_token_hash', hash('sha256', $data['registration_token']))
                ->whereNull('user_id')->where('created_at', '>=', now()->subDays(7))->lockForUpdate()->firstOrFail();
            abort_unless(strcasecmp($reservation->email, $request->user()->email) === 0, 404);
            $reservation->user_id = $request->user()->id;
            $reservation->registration_token_hash = null;
            $reservation->save();

            return $reservation;
        }, 3));
    }

    private function ensureEditable(Reservation $reservation): void
    {
        $scheduled = CarbonImmutable::parse($reservation->date.' '.$reservation->time, config('reservations.timezone'));
        abort_unless($reservation->status === 'pending' && $scheduled->isFuture(), 409,
            'Seules les reservations en attente et a venir peuvent etre modifiees ou annulees.');
    }
}
