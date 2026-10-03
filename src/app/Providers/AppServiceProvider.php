<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\DefaultTwoFactorPolicy;
use App\Contracts\TwoFactorPolicy;
use App\Events\Auth\EmailVerificationRequested;
use App\Events\Auth\MagicLinkRequested;
use App\Events\Auth\UserRegistered;
use App\Listeners\Auth\SendEmailVerificationNotification;
use App\Listeners\Auth\SendMagicLinkNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Google2FA::class);
        $this->app->bind(TwoFactorPolicy::class, DefaultTwoFactorPolicy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(UserRegistered::class, [SendEmailVerificationNotification::class, 'onRegistered']);
        Event::listen(EmailVerificationRequested::class, [SendEmailVerificationNotification::class, 'onRequested']);
        Event::listen(MagicLinkRequested::class, [SendMagicLinkNotification::class, 'handle']);

        // Named buckets — full matrix + reflection test arrives in T10/006.
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-resend', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('auth-forgot', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('auth-reset', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-magic-request', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('auth-magic-consume', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-2fa', fn (Request $request) => Limit::perMinute(10)->by($request->user() ? (string) $request->user()->id : (string) $request->ip()));
    }
}
