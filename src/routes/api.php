<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutAllController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResendVerificationController;
use App\Http\Controllers\Api\Auth\SessionController;
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

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', CurrentUserController::class)->name('api.v1.auth.me');
        Route::post('logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('logout-all', LogoutAllController::class)->name('api.v1.auth.logout_all');

        Route::get('sessions', [SessionController::class, 'index'])->name('api.v1.auth.sessions.index');
        Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy'])
            ->whereNumber('sessionId')
            ->name('api.v1.auth.sessions.destroy');
    });
});
