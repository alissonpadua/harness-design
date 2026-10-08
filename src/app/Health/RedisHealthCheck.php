<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Support\Facades\Redis;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class RedisHealthCheck extends Check
{
    public function run(): Result
    {
        try {
            Redis::connection()->ping();

            return Result::make()->ok();
        } catch (\Throwable $e) {
            return Result::make()->failed('redis unreachable');
        }
    }
}
