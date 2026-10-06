<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\OrgTwoFactorPolicy;
use App\Billing\FakeGateway;
use App\Billing\StripeGateway;
use App\Contracts\Billing\PaymentGateway;
use App\Contracts\Org\OrgEntitlements;
use App\Contracts\Org\PlanOrgEntitlements;
use App\Contracts\TwoFactorPolicy;
use App\Events\Auth\EmailChangeRequested;
use App\Events\Auth\EmailVerificationRequested;
use App\Events\Auth\MagicLinkRequested;
use App\Events\Auth\UserRegistered;
use App\Events\Org\MemberInvited;
use App\Listeners\Auth\SendEmailChangeNotifications;
use App\Listeners\Auth\SendEmailVerificationNotification;
use App\Listeners\Auth\SendMagicLinkNotification;
use App\Listeners\Org\CreatePersonalWorkspaceOnRegistration;
use App\Listeners\Org\SendOrgInviteMail;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\EdDSA\EdDSA;
use Cose\Algorithm\Signature\RSA\RS256;
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

        // asbiin/laravel-webauthn hardcodes insecure RS1 into its COSE manager binding
        // (fatal E_USER_ERROR on PHP 8.5 + web-auth/cose-lib >= 4.8) — safe subset instead.
        $this->app->singleton(CoseAlgorithmManager::class, fn () => (new CoseAlgorithmManager)
            ->add(new ES256, new ES384, new ES512, new RS256, new EdDSA));
        $this->app->bind(TwoFactorPolicy::class, OrgTwoFactorPolicy::class);
        $this->app->bind(OrgEntitlements::class, PlanOrgEntitlements::class);

        $this->app->singleton(PaymentGateway::class, fn () => match ((string) config('billing.driver')) {
            'fake' => new FakeGateway,
            default => new StripeGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(UserRegistered::class, [SendEmailVerificationNotification::class, 'onRegistered']);
        Event::listen(UserRegistered::class, [CreatePersonalWorkspaceOnRegistration::class, 'handle']);
        Event::listen(EmailVerificationRequested::class, [SendEmailVerificationNotification::class, 'onRequested']);
        Event::listen(MagicLinkRequested::class, [SendMagicLinkNotification::class, 'handle']);
        Event::listen(MemberInvited::class, [SendOrgInviteMail::class, 'handle']);
        Event::listen(EmailChangeRequested::class, [SendEmailChangeNotifications::class, 'handle']);

        // Named buckets — full matrix + reflection test arrives in T10/006.
        RateLimiter::for('admin-generic', fn (Request $request) => Limit::perMinute(60)->by($request->user() ? (string) $request->user()->id : (string) $request->ip()));
        RateLimiter::for('org-mutations', fn (Request $request) => Limit::perMinute(60)->by($request->user() ? (string) $request->user()->id : (string) $request->ip()));
        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user() ? $request->user()->id : $request->ip())));
        RateLimiter::for('billing-webhook', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-resend', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('auth-forgot', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('auth-reset', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-magic-request', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('auth-magic-consume', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-oauth', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-passkey', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-2fa', fn (Request $request) => Limit::perMinute(10)->by($request->user() ? (string) $request->user()->id : (string) $request->ip()));
    }
}
