<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Data\Auth\LoginTokenData;
use App\Exceptions\EmailNotVerifiedException;
use App\Exceptions\LoginFailedException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class LoginAction
{
    public function __construct(private IssueDeviceTokenAction $issueToken) {}

    public function handle(LoginData $data, string $ip, ?string $userAgent): LoginTokenData
    {
        $user = User::query()->where('email', $data->email)->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            throw new LoginFailedException;
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        return $this->issueToken->handle($user, $data->device_type, $ip, $userAgent);
    }
}
