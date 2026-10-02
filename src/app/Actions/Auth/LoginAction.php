<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Data\Auth\LoginTokenData;
use App\Events\Auth\OtherLoginDetected;
use App\Exceptions\EmailNotVerifiedException;
use App\Exceptions\LoginFailedException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class LoginAction
{
    public function handle(LoginData $data, string $ip, ?string $userAgent): LoginTokenData
    {
        $user = User::query()->where('email', $data->email)->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            throw new LoginFailedException;
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        $type = $data->device_type->value;
        $otherActive = $user->tokens()->where('device_type', '!=', $type)->exists();

        // One token per device type (locked decision 1.2): replace same-type only.
        $user->tokens()->where('device_type', $type)->delete();

        $issued = $user->createToken($type, ['*']);
        $issued->accessToken->forceFill([
            'device_type' => $type,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ])->save();

        if ($otherActive) {
            event(new OtherLoginDetected($user, $data->device_type));
        }

        return new LoginTokenData(token: $issued->plainTextToken, device_type: $type);
    }
}
