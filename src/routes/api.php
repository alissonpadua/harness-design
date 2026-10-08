<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\ConfirmEmailController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutAllController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MagicLinkController;
use App\Http\Controllers\Api\Auth\OAuthController;
use App\Http\Controllers\Api\Auth\PasskeyController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResendVerificationController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\Auth\VerifyEmailController;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Api\Billing\CheckoutReturnController;
use App\Http\Controllers\Api\Billing\WebhookController;
use App\Http\Controllers\Api\Notifications\NotificationController;
use App\Http\Controllers\Api\Org\IntegrationTokenController;
use App\Http\Controllers\Api\Org\InviteController;
use App\Http\Controllers\Api\Org\InviteLinkController;
use App\Http\Controllers\Api\Org\MemberController;
use App\Http\Controllers\Api\Org\OrganizationController;
use App\Http\Controllers\Api\Org\OrgAuditController;
use App\Http\Controllers\Api\Org\OrgLogoController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\Profile\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('v1/ping', PingController::class)->name('api.v1.ping');

Route::prefix('v1/auth')->group(function (): void {
    // Public login plane (verification gate lives in the actions, AC-001.3).
    Route::post('register', RegisterController::class)
        ->middleware('throttle:auth-register')
        ->name('api.v1.auth.register');

    Route::post('email/verify/resend', ResendVerificationController::class)
        ->middleware('throttle:auth-resend')
        ->name('api.v1.auth.resend');

    Route::get('verify-email/{userId}/{token}', VerifyEmailController::class)
        ->whereNumber('userId')
        ->middleware('throttle:auth-verify')
        ->name('api.v1.auth.verify');

    Route::get('confirm-email/{userId}/{token}', ConfirmEmailController::class)
        ->whereNumber('userId')
        ->middleware('throttle:auth-verify')
        ->name('api.v1.auth.confirm-email');

    Route::post('login', LoginController::class)
        ->middleware('throttle:auth-login')
        ->name('api.v1.auth.login');

    Route::post('forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:auth-forgot')
        ->name('api.v1.auth.forgot');

    Route::post('reset-password', ResetPasswordController::class)
        ->middleware('throttle:auth-reset')
        ->name('api.v1.auth.reset');

    Route::post('magic-link/request', [MagicLinkController::class, 'request'])
        ->middleware('throttle:auth-magic-request')
        ->name('api.v1.auth.magic.request');

    Route::post('magic-link/consume', [MagicLinkController::class, 'consume'])
        ->middleware('throttle:auth-magic-consume')
        ->name('api.v1.auth.magic.consume');

    Route::prefix('oauth/{provider}')->middleware('throttle:auth-oauth')->group(function (): void {
        Route::post('redirect', [OAuthController::class, 'redirect'])->name('api.v1.auth.oauth.redirect');
        Route::post('exchange', [OAuthController::class, 'exchange'])->name('api.v1.auth.oauth.exchange');
    });

    Route::post('passkeys/authenticate/options', [PasskeyController::class, 'authOptions'])
        ->middleware('throttle:auth-passkey')
        ->name('api.v1.auth.passkeys.auth_options');

    Route::post('passkeys/authenticate', [PasskeyController::class, 'authenticate'])
        ->middleware('throttle:auth-passkey')
        ->name('api.v1.auth.passkeys.authenticate');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', CurrentUserController::class)->name('api.v1.auth.me');
        Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('logout-all', LogoutAllController::class)->name('api.v1.auth.logout_all');

        Route::get('sessions', [SessionController::class, 'index'])->name('api.v1.auth.sessions.index');
        Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy'])
            ->whereNumber('sessionId')
            ->name('api.v1.auth.sessions.destroy');

        Route::prefix('passkeys')->middleware('not-impersonating')->name('api.v1.auth.passkeys.')->group(function (): void {
            Route::post('register/options', [PasskeyController::class, 'registerOptions'])->name('register.options');
            Route::post('register', [PasskeyController::class, 'register'])->name('register');
            Route::get('/', [PasskeyController::class, 'index'])->name('index');
            Route::delete('{passkey}', [PasskeyController::class, 'destroy'])
                ->whereNumber('passkey')
                ->name('destroy');
        });

        Route::prefix('2fa')->middleware('not-impersonating')->name('api.v1.auth.2fa.')->group(function (): void {
            Route::post('enroll', [TwoFactorController::class, 'enroll'])->middleware('throttle:auth-2fa')->name('enroll');
            Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:auth-2fa')->name('confirm');
            Route::post('disable', [TwoFactorController::class, 'disable'])->middleware('throttle:auth-2fa')->name('disable');
        });
    });
});

// Spec 006 Q4=B: sole public-read surface — the org logo (bytes re-encoded on
// upload; s3 answers 302 to a short signed URL, other drivers stream).
Route::get('v1/public/orgs/{identifier}/logo', [OrgLogoController::class, 'show'])->name('api.v1.public.logo');

Route::prefix('v1/orgs')->middleware(['auth:sanctum', 'not-suspended', 'audit-impersonated', 'throttle:org-mutations', 'throttle:plan-api'])->name('api.v1.orgs.')->group(function (): void {
    Route::get('/', [OrganizationController::class, 'index'])->name('index');
    Route::post('/', [OrganizationController::class, 'store'])->name('store');
    Route::get('{organization}', [OrganizationController::class, 'show'])->name('show');
    Route::patch('{organization}', [OrganizationController::class, 'update'])->name('update');
    Route::delete('{organization}', [OrganizationController::class, 'destroy'])->middleware('not-impersonating')->name('destroy');
    Route::post('{organization}/switch', [OrganizationController::class, 'switch'])->name('switch');
    Route::get('{organization}/members', [MemberController::class, 'index'])->name('members.index');
    Route::patch('{organization}/members/{user}', [MemberController::class, 'update'])->whereNumber('user')->name('members.update');
    Route::post('{organization}/members/{user}/suspend', [MemberController::class, 'suspend'])->whereNumber('user')->name('members.suspend');
    Route::post('{organization}/members/{user}/unsuspend', [MemberController::class, 'unsuspend'])->whereNumber('user')->name('members.unsuspend');
    Route::delete('{organization}/members/{user}', [MemberController::class, 'destroy'])->whereNumber('user')->name('members.destroy');

    Route::get('{organization}/invites', [InviteController::class, 'index'])->name('invites.index');
    Route::post('{organization}/invites', [InviteController::class, 'store'])->name('invites.store');
    Route::delete('{organization}/invites/{invite}', [InviteController::class, 'destroy'])->whereNumber('invite')->name('invites.destroy');

    Route::get('{organization}/invite-links', [InviteLinkController::class, 'index'])->name('links.index');
    Route::post('{organization}/invite-links', [InviteLinkController::class, 'store'])->name('links.store');
    Route::delete('{organization}/invite-links/{link}', [InviteLinkController::class, 'destroy'])->whereNumber('link')->name('links.destroy');

    Route::post('{organization}/leave', [MemberController::class, 'leave'])->name('leave');

    Route::get('{organization}/audit', [OrgAuditController::class, 'index'])->name('audit');

    Route::put('{organization}/logo', [OrgLogoController::class, 'update'])->name('logo.update');
    Route::delete('{organization}/logo', [OrgLogoController::class, 'destroy'])->name('logo.destroy');

    Route::prefix('{organization}/tokens')->name('tokens.')->middleware('throttle:tokens-mutations')->group(function (): void {
        Route::get('/', [IntegrationTokenController::class, 'index'])->name('index');
        Route::post('/', [IntegrationTokenController::class, 'store'])->name('store');
        Route::delete('{tokenId}', [IntegrationTokenController::class, 'destroy'])->whereNumber('tokenId')->name('destroy');
    });

    Route::prefix('{organization}/billing')->name('billing.')->middleware('throttle:billing')->group(function (): void {
        Route::post('checkout', [BillingController::class, 'checkout'])->name('checkout');
        Route::get('subscription', [BillingController::class, 'subscription'])->name('subscription');
        Route::post('subscription/preview', [BillingController::class, 'preview'])->name('subscription.preview');
        Route::post('subscription/change', [BillingController::class, 'change'])->name('subscription.change');
        Route::post('subscription/cancel', [BillingController::class, 'cancel'])->name('subscription.cancel');
        Route::get('payment-methods', [BillingController::class, 'paymentMethods'])->name('payment_methods.index');
        Route::post('payment-methods/setup', [BillingController::class, 'setup'])->name('payment_methods.setup');
        Route::post('payment-methods', [BillingController::class, 'attach'])->name('payment_methods.attach');
        Route::post('payment-methods/{paymentMethod}/default', [BillingController::class, 'setDefault'])->name('payment_methods.default');
        Route::delete('payment-methods/{paymentMethod}', [BillingController::class, 'detach'])->name('payment_methods.detach');
        Route::get('invoices', [BillingController::class, 'invoices'])->name('invoices.index');
        Route::get('invoices/{invoice}/download', [BillingController::class, 'download'])->whereNumber('invoice')->name('invoices.download');
    });
    Route::post('{organization}/transfer-ownership', [MemberController::class, 'transfer'])->middleware('not-impersonating')->name('transfer');
});

Route::post('v1/invites/accept', [InviteController::class, 'accept'])
    ->middleware(['auth:sanctum', 'throttle:org-mutations'])
    ->name('api.v1.invites.accept');

Route::post('v1/invite-links/join', [InviteLinkController::class, 'join'])
    ->middleware(['auth:sanctum', 'throttle:org-mutations'])
    ->name('api.v1.invitelinks.join');

Route::get('v1/billing/checkout/return', CheckoutReturnController::class)
    ->middleware('throttle:billing-webhook')
    ->name('api.v1.billing.checkout.return');

Route::post('v1/billing/webhook/stripe', WebhookController::class)
    ->middleware('throttle:billing-webhook')
    ->name('api.v1.billing.webhook');

Route::prefix('v1/notifications')->middleware(['auth:sanctum', 'not-suspended', 'audit-impersonated'])->name('api.v1.notifications.')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('preferences', [NotificationController::class, 'preferences'])->name('preferences.show');
    Route::put('preferences', [NotificationController::class, 'updatePreferences'])
        ->middleware('throttle:billing')
        ->name('preferences.update');
});

Route::prefix('v1/profile')->middleware(['auth:sanctum', 'not-suspended', 'audit-impersonated'])->name('api.v1.profile.')->group(function (): void {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::put('/', [ProfileController::class, 'update'])->name('update');
    Route::put('email', [ProfileController::class, 'email'])->middleware('not-impersonating')->name('email');
    Route::put('password', [ProfileController::class, 'password'])->middleware('not-impersonating')->name('password');
    Route::post('delete-account', [ProfileController::class, 'deleteAccount'])->middleware('not-impersonating')->name('delete');
});
