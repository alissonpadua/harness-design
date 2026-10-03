<?php

declare(strict_types=1);

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
use App\Http\Controllers\Api\PingController;
use Illuminate\Support\Facades\Route;

Route::get('v1/ping', PingController::class)->name('api.v1.ping');

Route::prefix('v1/auth')->group(function (): void {
    // Public login plane (verification gate lives in LoginAction, AC-001.3).
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

    Route::post('passkeys/authenticate/options', [PasskeyController::class, 'authOptions'])
        ->middleware('throttle:auth-passkey')
        ->name('api.v1.auth.passkeys.auth_options');

    Route::post('passkeys/authenticate', [PasskeyController::class, 'authenticate'])
        ->middleware('throttle:auth-passkey')
        ->name('api.v1.auth.passkeys.authenticate');

    Route::prefix('oauth/{provider}')->middleware('throttle:auth-oauth')->group(function (): void {
        Route::post('redirect', [OAuthController::class, 'redirect'])->name('api.v1.auth.oauth.redirect');
        Route::post('exchange', [OAuthController::class, 'exchange'])->name('api.v1.auth.oauth.exchange');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', CurrentUserController::class)->name('api.v1.auth.me');

        Route::prefix('passkeys')->name('api.v1.auth.passkeys.')->group(function (): void {
            Route::post('register/options', [PasskeyController::class, 'registerOptions'])->name('register.options');
            Route::post('register', [PasskeyController::class, 'register'])->name('register');
            Route::get('/', [PasskeyController::class, 'index'])->name('index');
            Route::delete('{passkey}', [PasskeyController::class, 'destroy'])
                ->whereNumber('passkey')
                ->name('destroy');
        });

        Route::prefix('2fa')->name('api.v1.auth.2fa.')->group(function (): void {
            Route::post('enroll', [TwoFactorController::class, 'enroll'])->middleware('throttle:auth-2fa')->name('enroll');
            Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:auth-2fa')->name('confirm');
            Route::post('disable', [TwoFactorController::class, 'disable'])->middleware('throttle:auth-2fa')->name('disable');
        });
        Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('logout-all', LogoutAllController::class)->name('api.v1.auth.logout_all');

        Route::get('sessions', [SessionController::class, 'index'])->name('api.v1.auth.sessions.index');
        Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy'])
            ->whereNumber('sessionId')
            ->name('api.v1.auth.sessions.destroy');
    });
});
