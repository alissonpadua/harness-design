<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Auth\OtherLoginDetected;

final readonly class SendNewDeviceLoginNotification
{
    public function __construct(private DispatchNotification $notify) {}

    public function handle(OtherLoginDetected $event): void
    {
        $request = request();

        $this->notify->user($event->user, 'auth.new_device_login', [
            'ip' => (string) $request->ip(),
            'agent' => substr((string) $request->userAgent(), 0, 120),
            'time' => now()->toIso8601String(),
        ]);
    }
}
