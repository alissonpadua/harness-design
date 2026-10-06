<?php

declare(strict_types=1);

namespace App\Listeners\Org;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Org\OwnershipTransferred;

final readonly class NotifyOwnershipTransferred
{
    public function __construct(private DispatchNotification $notify) {}

    public function handle(OwnershipTransferred $event): void
    {
        $this->notify->org(
            $event->organization,
            'org.ownership_transferred',
            [
                'org_name' => $event->organization->name,
                'from_name' => $event->previousOwner->name,
                'to_name' => $event->newOwner->name,
            ],
            extraUserIds: [$event->newOwner->id, $event->previousOwner->id],
        );
    }
}
