<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRestaurantServiceScheduleRequest;
use App\Http\Requests\UpdateRestaurantServiceScheduleRequest;
use App\Models\Reservation;
use App\Models\RestaurantBookingLock;
use App\Models\RestaurantServiceSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AdminRestaurantServiceScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => RestaurantServiceSchedule::query()
                ->orderBy('day_of_week')
                ->orderBy('service_key')
                ->get(),
        ]);
    }

    public function store(StoreRestaurantServiceScheduleRequest $request): JsonResponse
    {
        $schedule = DB::transaction(function () use ($request): RestaurantServiceSchedule {
            RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);

            return RestaurantServiceSchedule::query()->create($request->safe()->all());
        }, 3);

        return response()->json(['data' => $schedule], 201);
    }

    public function update(UpdateRestaurantServiceScheduleRequest $request, RestaurantServiceSchedule $schedule): JsonResponse
    {
        $updatedSchedule = DB::transaction(function () use ($request, $schedule): RestaurantServiceSchedule {
            RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);
            $lockedSchedule = RestaurantServiceSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $lockedSchedule->fill($request->safe()->all());
            $lockedSchedule->save();

            return $lockedSchedule;
        }, 3);

        return response()->json(['data' => $updatedSchedule]);
    }

    public function destroy(RestaurantServiceSchedule $schedule): Response
    {
        DB::transaction(function () use ($schedule): void {
            RestaurantBookingLock::query()->lockForUpdate()->findOrFail(1);
            $lockedSchedule = RestaurantServiceSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            $hasFutureBookings = Reservation::query()
                ->where('service_key', $lockedSchedule->service_key)
                ->where('date', '>=', now(config('reservations.timezone'))->toDateString())
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();
            abort_if($hasFutureBookings, 409, 'Ce service ne peut pas etre supprime car des reservations futures lui sont rattachees.');
            $lockedSchedule->delete();
        }, 3);

        return response()->noContent();
    }
}
