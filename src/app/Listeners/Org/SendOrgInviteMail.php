<?php

declare(strict_types=1);

namespace App\Listeners\Org;

use App\Events\Org\MemberInvited;
use App\Notifications\OrgInviteNotification;
use Illuminate\Support\Facades\Notification;

final class SendOrgInviteMail
{
    public function handle(MemberInvited $event): void
    {
        Notification::route('mail', $event->invite->email)
            ->notify(new OrgInviteNotification($event->invite, $event->organization->name));
    }
}
