<?php

declare(strict_types=1);

use App\Health\QueueRoundtripHealthCheck;
use App\Health\RedisHealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;

Health::checks([
    DatabaseCheck::new(),
    RedisHealthCheck::new(),
    QueueRoundtripHealthCheck::new(),
]);

Route::get('/up', function (): JsonResponse {
    $payload = [];
    $ok = true;

    foreach (Health::registeredChecks() as $check) {
        $result = $check->run();
        $isOk = $result->status === Status::ok();
        $payload[] = [
            'name' => class_basename($check::class),
            'status' => $isOk ? 'ok' : 'problem',
        ];

        if (! $isOk) {
            $ok = false;
        }
    }

    return response()->json(['status' => $ok ? 'ok' : 'problem', 'checks' => $payload], $ok ? 200 : 503);
})->name('health');
