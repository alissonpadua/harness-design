<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Auth\EmailVerificationRequested;
use App\Events\Auth\UserRegistered;
use App\Listeners\Auth\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        Event::listen(UserRegistered::class, [SendEmailVerificationNotification::class, 'onRegistered']);
        Event::listen(EmailVerificationRequested::class, [SendEmailVerificationNotification::class, 'onRequested']);

        // Named buckets — full matrix + reflection test arrives in T10/006.
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-resend', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
    }
}
