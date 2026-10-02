<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Events\Auth\MagicLinkRequested;
use App\Models\User;

/**
 * AC-001.11 — generic 202 upstream; only live accounts get an event. The
 * listener owns link issuance + mail (no notify inside Actions — arch rule).
 */
final readonly class RequestMagicLinkAction
{
    public function handle(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            event(new MagicLinkRequested($user));
        }
    }
}
