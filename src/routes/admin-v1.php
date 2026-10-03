<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AdminPingController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/v1')->middleware(['auth:sanctum', 'role:super-admin'])->name('admin.v1.')->group(function (): void {
    Route::get('ping', AdminPingController::class)->name('ping');
});
