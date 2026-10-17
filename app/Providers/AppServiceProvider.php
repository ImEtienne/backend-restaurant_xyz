<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-client', fn (User $user): bool => ! $user->is_admin && $user->is_active === true);

        Gate::define('manage-users', fn (User $user): bool => $user->is_admin === true);

        Gate::define('manage-reservations', fn (User $user): bool => $user->is_admin === true);

        RateLimiter::for('admin-login', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('reservations', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
