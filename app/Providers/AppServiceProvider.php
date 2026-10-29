<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Customer authentication will use its own guard. Until then, do not expose
        // a password-only Fortify route that bypasses the admin panel's MFA flow.
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id', $request->ip()));
        });

        // Deny shoppers and the session used to enroll a new administrator's MFA.
        // This relies on Filament being the only authentication entry point;
        // customer authentication must use a separate guard when it is introduced.
        Horizon::auth(function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User
                && $user->is_admin
                && filled($user->getAppAuthenticationSecret());
        });
    }
}

