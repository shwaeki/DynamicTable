<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ExampleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExampleRegistry::class);
    }

    public function boot(): void
    {
        // The demo has no login screen; sign in as a fixed user so the saved
        // views example has an owner, and grant the system-views gate so
        // "Share with everyone" is available.
        if (! app()->runningInConsole() && Auth::guest()) {
            $user = User::query()->first();

            if ($user !== null) {
                Auth::setUser($user);
            }
        }

        Gate::define('manage-dynamic-table-system-views', fn (?User $user): bool => true);

    }
}
