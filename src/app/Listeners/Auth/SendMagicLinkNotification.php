<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Auth\MagicLinkRequested;
use App\Models\AuthLink;

final readonly class SendMagicLinkNotification
{
    public function __construct(private DispatchNotification $notify) {}

    public function handle(MagicLinkRequested $event): void
    {
        $minutes = (int) config('auth.links.magic_link_minutes');
        $link = AuthLink::issue($event->user, 'magic_link', minutes: $minutes);

        $this->notify->user($event->user, 'auth.magic_link', [
            'token' => (string) $link->token,
            'minutes' => $minutes,
            'expires_at' => (string) $link->expires_at,
        ]);
    }
}
