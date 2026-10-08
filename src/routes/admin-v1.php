<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AdminPingController;
use App\Http\Controllers\Api\Admin\AuditController;
use App\Http\Controllers\Api\Admin\ImpersonationController;
use App\Http\Controllers\Api\Admin\OpsController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\PlanController;
use App\Http\Controllers\Api\Admin\UserController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/v1')->middleware(['auth:sanctum', 'not-impersonating', 'role:super-admin', 'not-suspended', 'throttle:admin-generic', SubstituteBindings::class])->name('admin.v1.')->group(function (): void {
    Route::get('ping', AdminPingController::class)->name('ping');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan')->name('plans.show');
    Route::patch('plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->name('plans.update');

    Route::get('ops/horizon-url', [OpsController::class, 'horizonUrl'])->name('ops.horizon_url');
    Route::post('billing/webhooks/{event}/replay', [OpsController::class, 'replayWebhook'])->whereNumber('event')->name('webhooks.replay');

    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('orgs', [AdminOrganizationController::class, 'index'])->name('orgs.index');
    Route::get('orgs/{organization}', [AdminOrganizationController::class, 'show'])->whereNumber('organization')->name('orgs.show');
    Route::post('orgs/{organization}/restore', [AdminOrganizationController::class, 'restore'])->whereNumber('organization')->name('orgs.restore');
    Route::post('orgs/{organization}/plan', [AdminOrganizationController::class, 'plan'])->whereNumber('organization')->name('orgs.plan');
    Route::put('orgs/{organization}/entitlements', [AdminOrganizationController::class, 'entitlements'])->whereNumber('organization')->name('orgs.entitlements');

    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])->whereNumber('user')->name('impersonations.start');
    Route::get('impersonations', [ImpersonationController::class, 'index'])->name('impersonations.index');
    Route::delete('impersonations/{token}', [ImpersonationController::class, 'destroy'])->whereNumber('token')->name('impersonations.stop');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->whereNumber('user')->name('users.suspend');
    Route::post('users/{user}/unsuspend', [UserController::class, 'unsuspend'])->whereNumber('user')->name('users.unsuspend');
    Route::post('users/{user}/restore', [UserController::class, 'restore'])->whereNumber('user')->name('users.restore');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
});
