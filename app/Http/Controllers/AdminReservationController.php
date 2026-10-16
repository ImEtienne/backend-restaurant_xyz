<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterReservationsRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Http\Resources\AdminReservationResource;
use App\Models\Reservation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminReservationController extends Controller
{
    public function index(FilterReservationsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Reservation::query();

        if (! empty($filters['date'])) {
            $query->where('date', $filters['date']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return AdminReservationResource::collection(
            $query->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')
                ->paginate(20)->withQueryString(),
        );
    }

    public function update(UpdateReservationStatusRequest $request, Reservation $reservation): AdminReservationResource
    {
        $updated = Reservation::query()->whereKey($reservation->id)->where('status', 'pending')->update([
            'status' => $request->validated('status'),
        ]);

        abort_if($updated === 0, 409, 'Cette demande a deja ete traitee.');

        return new AdminReservationResource($reservation->refresh());
    }
}
