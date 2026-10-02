<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PingController;
use Illuminate\Support\Facades\Route;

Route::get('v1/ping', PingController::class)->name('api.v1.ping');
