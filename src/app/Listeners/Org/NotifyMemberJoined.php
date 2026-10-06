<?php

declare(strict_types=1);

namespace App\Listeners\Org;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Org\MemberJoined;

final readonly class NotifyMemberJoined
{
    public function __construct(private DispatchNotification $notify) {}

    public function handle(MemberJoined $event): void
    {
        $membership = $event->organization->membershipFor($event->user);

        $this->notify->org($event->organization, 'org.member_joined', [
            'org_name' => $event->organization->name,
            'member_name' => $event->user->name,
            'role' => $membership?->role->value ?? 'member',
        ]);
    }
}
