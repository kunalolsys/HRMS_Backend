<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
        // Superadmin ko direct access dene ke liye (Safe check ke sath)
        Gate::before(function ($user, $ability) {
            if ($user && method_exists($user, 'hasRole')) {
                return $user->hasRole('Super Admin') ? true : null;
            }
            return null;
        });
    }
}
