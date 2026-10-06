<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AdminPingController;
use App\Http\Controllers\Api\Admin\PlanController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/v1')->middleware(['auth:sanctum', 'role:super-admin', 'throttle:admin-generic', SubstituteBindings::class])->name('admin.v1.')->group(function (): void {
    Route::get('ping', AdminPingController::class)->name('ping');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan')->name('plans.show');
    Route::patch('plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->name('plans.update');
});
