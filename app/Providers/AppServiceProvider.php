<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by($this->identifierKey($request)),
        ]);

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perMinute(2)->by($this->identifierKey($request)),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by($this->identifierKey($request)),
        ]);
    }

    private function identifierKey(Request $request): string
    {
        return Str::lower(trim((string) $request->input('identifier', 'guest')));
    }
}
