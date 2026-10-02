<?php

declare(strict_types=1);

namespace App\Events\Auth;

use App\Enums\DeviceType;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OtherLoginDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly DeviceType $newDeviceType,
    ) {}
}
