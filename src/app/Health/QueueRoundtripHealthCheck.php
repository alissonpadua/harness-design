<?php

declare(strict_types=1);

namespace App\Health;

use App\Jobs\Health\PingQueueJob;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class QueueRoundtripHealthCheck extends Check
{
    public function run(): Result
    {
        $token = bin2hex(random_bytes(8));

        try {
            dispatch(new PingQueueJob($token));
        } catch (\Throwable $e) {
            return Result::make()->failed('queue dispatch rejected: '.$e->getMessage());
        }

        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            if (Cache::get('health:queue:'.$token) === 'consumed') {
                return Result::make()->ok();
            }

            usleep(100_000);
        }

        return Result::make()->failed('queue not consumed within timeout');
    }
}
