<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardSearchRequest;
use App\Http\Resources\AdminReservationResource;
use App\Http\Resources\AdminUserResource;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AdminSearchController extends Controller
{
    public function __invoke(DashboardSearchRequest $request): JsonResponse
    {
        $term = $request->validated('q');
        $limit = $request->integer('limit', 5);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $users = User::query()->where(function (Builder $query) use ($term, $pattern): void {
            $query->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            $this->matchIdentifier($query, $term);
        })->orderByDesc('id')->limit($limit + 1)->get();
        $reservations = Reservation::query()->where(function (Builder $query) use ($term, $pattern): void {
            $query->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("date LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(status) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            $this->matchIdentifier($query, $term);
        })->orderByDesc('id')->limit($limit + 1)->get();

        return response()->json([
            'data' => [
                'users' => AdminUserResource::collection($users->take($limit))->resolve($request),
                'reservations' => AdminReservationResource::collection($reservations->take($limit))->resolve($request),
            ],
            'meta' => [
                'q' => $term,
                'limit_per_type' => $limit,
                'has_more' => ['users' => $users->count() > $limit, 'reservations' => $reservations->count() > $limit],
            ],
        ]);
    }

    private function matchIdentifier(Builder $query, string $term): void
    {
        $identifier = str_starts_with($term, '#') ? substr($term, 1) : $term;
        if (ctype_digit($identifier) && strlen($identifier) <= 18) {
            $query->orWhere('id', (int) $identifier);
        }
    }
}
