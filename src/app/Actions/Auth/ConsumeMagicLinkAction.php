<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\ConsumeMagicLinkData;
use App\Data\Auth\LoginTokenData;
use App\Exceptions\AuthLinkException;
use App\Models\AuthLink;

final readonly class ConsumeMagicLinkAction
{
    public function __construct(private IssueDeviceTokenAction $issueToken) {}

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

        $link->forceFill(['used_at' => now()])->save();

        // Locked decision #1: consuming a magic link proves mailbox control.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $this->issueToken->handle($user, $data->device_type, $ip, $userAgent);
    }
}
