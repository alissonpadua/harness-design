<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResendVerificationController;
use App\Http\Controllers\Api\Auth\VerifyEmailController;
use App\Http\Controllers\Api\PingController;
use Illuminate\Support\Facades\Route;

Route::get('v1/ping', PingController::class)->name('api.v1.ping');

Route::prefix('v1/auth')->group(function (): void {
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
});
