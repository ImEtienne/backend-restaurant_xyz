<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRestaurantServiceExceptionRequest;
use App\Http\Requests\UpdateRestaurantServiceExceptionRequest;
use App\Models\RestaurantBookingLock;
use App\Models\RestaurantServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminRestaurantServiceExceptionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => RestaurantServiceException::query()
                ->orderBy('start_date')
                ->orderBy('service_key')
                ->get(),
        ]);
    }

    public function store(StoreRestaurantServiceExceptionRequest $request): JsonResponse
    {
        $exception = DB::transaction(function () use ($request): RestaurantServiceException {
            RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);
            $this->ensureNoOverlappingPeriod(
                $request->validated('service_key'),
                $request->validated('start_date'),
                $request->validated('end_date'),
            );

            return RestaurantServiceException::query()->create($request->safe()->all());
        }, 3);

        return response()->json(['data' => $exception], 201);
    }

    public function update(UpdateRestaurantServiceExceptionRequest $request, RestaurantServiceException $exception): JsonResponse
    {
        $updatedException = DB::transaction(function () use ($request, $exception): RestaurantServiceException {
            RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);
            $lockedException = RestaurantServiceException::query()->lockForUpdate()->findOrFail($exception->id);
            $this->ensureNoOverlappingPeriod(
                $lockedException->service_key,
                $request->validated('start_date'),
                $request->validated('end_date'),
                $lockedException->id,
            );
            $lockedException->fill($request->safe()->all());
            $lockedException->save();

            return $lockedException;
        }, 3);

        return response()->json(['data' => $updatedException]);
    }

    public function destroy(RestaurantServiceException $exception): Response
    {
        DB::transaction(function () use ($exception): void {
            RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);
            RestaurantServiceException::query()->lockForUpdate()->findOrFail($exception->id)->delete();
        }, 3);

        return response()->noContent();
    }

    private function ensureNoOverlappingPeriod(string $serviceKey, string $startDate, string $endDate, ?int $exceptId = null): void
    {
        $overlapExists = RestaurantServiceException::query()
            ->where('service_key', $serviceKey)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($overlapExists) {
            throw ValidationException::withMessages([
                'start_date' => 'Cette periode chevauche une exception deja configuree pour ce service.',
            ]);
        }
    }
}
