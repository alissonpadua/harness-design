<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginTokenData;
use App\Enums\DeviceType;
use App\Events\Auth\OtherLoginDetected;
use App\Models\User;

/**
 * Single implementation of "replace same device type, spare the others"
 * (AC-001.5) — used by LoginAction and ConsumeMagicLinkAction.
 */
final readonly class IssueDeviceTokenAction
{
    public function handle(User $user, DeviceType $type, string $ip, ?string $userAgent): LoginTokenData
    {
        $value = $type->value;
        $otherActive = $user->tokens()->where('device_type', '!=', $value)->exists();

        $user->tokens()->where('device_type', $value)->delete();

        $issued = $user->createToken($value, ['*']);
        $issued->accessToken->forceFill([
            'device_type' => $value,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ])->save();

        if ($otherActive) {
            event(new OtherLoginDetected($user, $type));
        }

        return new LoginTokenData(token: $issued->plainTextToken, device_type: $value);
    }
}
