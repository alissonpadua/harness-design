<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\TwoFactorChallenge;
use App\Contracts\TwoFactorPolicy;
use App\Data\Auth\LoginData;
use App\Data\Auth\LoginTokenData;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\EmailNotVerifiedException;
use App\Exceptions\LoginFailedException;
use App\Exceptions\TwoFactorMandatoryException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class LoginAction
{
    public function __construct(
        private IssueDeviceTokenAction $issueToken,
        private TwoFactorPolicy $policy,
        private TwoFactorChallenge $challenge,
    ) {}

    public function handle(LoginData $data, string $ip, ?string $userAgent): LoginTokenData
    {
        $user = User::query()->where('email', $data->email)->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            throw new LoginFailedException;
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        if ($user->isSuspended()) {
            throw new AccountSuspendedException;
        }

        if ($this->policy->requires($user) && $user->two_factor_confirmed_at === null) {
            throw new TwoFactorMandatoryException;
        }

        $this->challenge->verify($user, $data->otp);

        return $this->issueToken->handle($user, $data->device_type, $ip, $userAgent);
    }
}
