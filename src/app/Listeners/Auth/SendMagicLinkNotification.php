<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Events\Auth\MagicLinkRequested;
use App\Models\AuthLink;
use App\Notifications\MagicLinkNotification;

final class SendMagicLinkNotification
{
    public function handle(MagicLinkRequested $event): void
    {
        $link = AuthLink::issue($event->user, 'magic_link', minutes: (int) config('auth.links.magic_link_minutes'));

        $event->user->notify(new MagicLinkNotification($link));
    }
}
