<?php

declare(strict_types=1);

namespace App\Listeners\Org;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Org\MemberInvited;
use App\Notifications\Support\NotifyUrls;

final readonly class SendOrgInviteMail
{
    public function __construct(private DispatchNotification $notify) {}

    public function handle(MemberInvited $event): void
    {
        // mail-only lane: the invitee has no account yet (spec 004 micro-decision 4)
        $this->notify->email($event->invite->email, 'org.invite_received', [
            'org_name' => $event->organization->name,
            'role' => $event->invite->role->value,
            'url' => NotifyUrls::invite((string) $event->invite->token()),
        ]);
    }
}
