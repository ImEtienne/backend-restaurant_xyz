<?php

use App\Http\Controllers\AdminClientActivationController;
use App\Http\Controllers\AdminReservationClientController;
use App\Http\Controllers\AdminReservationController;
use App\Http\Controllers\AdminSearchController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminStatsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ClientRegistrationController;
use App\Http\Controllers\ClientReservationController;
use App\Http\Controllers\ClientStatsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\UserSessionController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('/reservations', [ReservationController::class, 'store'])
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware(['throttle:reservations', 'cache.headers:no_store;private'])
    ->name('reservations.store');

Route::prefix('admin')->name('admin.')->middleware('cache.headers:no_store;private')->group(function (): void {
    Route::post('/login', [AdminSessionController::class, 'store'])
        ->middleware('throttle:admin-login')->name('login');

    Route::middleware(['auth:sanctum', EnsureActiveAccount::class])->group(function (): void {
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
    Route::middleware(['auth:sanctum', EnsureActiveAccount::class])->group(function (): void {
        Route::post('/logout', [UserSessionController::class, 'destroy'])->name('logout');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::apiResource('/admin/users', AdminUserController::class)->only(['index', 'show', 'store', 'update', 'destroy'])
            ->middleware('can:manage-users')->names('admin.users');
    });
});

Route::post('/client/register', ClientRegistrationController::class)->middleware(['throttle:5,1', 'cache.headers:no_store;private'])->name('client.register');
Route::middleware(['auth:sanctum', EnsureActiveAccount::class, 'cache.headers:no_store;private'])->group(function (): void {
    Route::patch('/admin/reservations/{reservation}/client', AdminReservationClientController::class)->middleware(['can:manage-users', 'can:manage-reservations'])->name('admin.reservations.client');
    Route::patch('/admin/users/{user}/activation', AdminClientActivationController::class)->middleware('can:manage-users')->name('admin.users.activation');
    Route::prefix('client')->name('client.')->middleware('can:access-client')->group(function (): void {
        Route::get('/stats', ClientStatsController::class)->name('stats');
        Route::post('/reservations/claim', [ClientReservationController::class, 'claim'])->middleware('throttle:5,1')->name('reservations.claim');
        Route::post('/reservations', [ClientReservationController::class, 'store'])->middleware('throttle:reservations')->name('reservations.store');
        Route::get('/reservations', [ClientReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [ClientReservationController::class, 'show'])->name('reservations.show');
        Route::patch('/reservations/{reservation}', [ClientReservationController::class, 'update'])->name('reservations.update');
        Route::post('/reservations/{reservation}/cancel', [ClientReservationController::class, 'cancel'])->name('reservations.cancel');
    });
});
