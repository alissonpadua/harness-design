<?php

declare(strict_types=1);

namespace App\Jobs\Health;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class PingQueueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Cache::put('health:queue:'.$this->token, 'consumed', now()->addMinute());
    }
}
