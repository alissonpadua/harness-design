<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\TwoFactorChallenge;
use App\Contracts\TwoFactorPolicy;
use App\Data\Auth\ConsumeMagicLinkData;
use App\Data\Auth\LoginTokenData;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\AuthLinkException;
use App\Exceptions\TwoFactorMandatoryException;
use App\Models\AuthLink;

final readonly class ConsumeMagicLinkAction
{
    public function __construct(
        private IssueDeviceTokenAction $issueToken,
        private TwoFactorPolicy $policy,
        private TwoFactorChallenge $challenge,
    ) {}

    public function handle(ConsumeMagicLinkData $data, string $ip, ?string $userAgent): LoginTokenData
    {
        /** @var AuthLink|null $link */
        $link = AuthLink::query()
            ->where('type', 'magic_link')
            ->where('token_hash', hash('sha256', $data->token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        $user = $link?->user;

        if ($link === null || $user === null) {
            // unknown / expired / consumed / deleted-owner all identical
            throw new AuthLinkException;
        }

        if ($user->isSuspended()) {
            throw new AccountSuspendedException;
        }

        // Challenge BEFORE consuming so a failed 2FA leaves the link reusable (AC-001.18).
        if ($this->policy->requires($user) && $user->two_factor_confirmed_at === null) {
            throw new TwoFactorMandatoryException;
        }

        $this->challenge->verify($user, $data->otp);

        $link->forceFill(['used_at' => now()])->save();

        // Locked decision #1: consuming a magic link proves mailbox control.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $this->issueToken->handle($user, $data->device_type, $ip, $userAgent);
    }
}
