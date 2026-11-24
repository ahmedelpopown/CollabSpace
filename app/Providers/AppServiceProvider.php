<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('auth-login-ip:'.$request->ip()),
                Limit::perMinute(5)->by(
                    'auth-login-email:'.hash('sha256', $email ?: 'missing-email')
                ),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perMinute(3)->by('auth-register:'.$request->ip());
        });

        RateLimiter::for('auth-google', function (Request $request): Limit {
            return Limit::perMinute(5)->by('auth-google:'.$request->ip());
        });
    }
}
