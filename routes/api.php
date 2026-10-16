<?php

use App\Http\Controllers\AdminReservationController;
use App\Http\Controllers\AdminSearchController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminStatsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\UserSessionController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('/reservations', [ReservationController::class, 'store'])
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware('throttle:reservations')
    ->name('reservations.store');

Route::prefix('admin')->name('admin.')->middleware('cache.headers:no_store;private')->group(function (): void {
    Route::post('/login', [AdminSessionController::class, 'store'])
        ->middleware('throttle:admin-login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('logout');

        Route::get('/search', AdminSearchController::class)->middleware(['can:manage-users', 'can:manage-reservations', 'throttle:60,1'])->name('search');

        Route::middleware('can:manage-reservations')->group(function (): void {
            Route::get('/stats', [AdminStatsController::class, 'summary'])->name('stats.summary');
            Route::get('/stats/daily', [AdminStatsController::class, 'daily'])->name('stats.daily');
            Route::get('/stats/hourly', [AdminStatsController::class, 'hourly'])->name('stats.hourly');
            Route::get('/me', [AdminSessionController::class, 'show'])->name('me');
            Route::get('/reservations', [AdminReservationController::class, 'index'])->name('reservations.index');
            Route::patch('/reservations/{reservation}', [AdminReservationController::class, 'update'])->name('reservations.update');
        });
    });
});

Route::middleware('cache.headers:no_store;private')->group(function (): void {
    Route::post('/login', [UserSessionController::class, 'store'])->middleware('throttle:admin-login')->name('login');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [UserSessionController::class, 'destroy'])->name('logout');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::apiResource('/admin/users', AdminUserController::class)->only(['index', 'show', 'store', 'update', 'destroy'])
            ->middleware('can:manage-users')->names('admin.users');
    });
});
