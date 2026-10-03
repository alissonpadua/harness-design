<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Events\Auth\EmailChangeRequested;
use App\Models\AuthLink;
use App\Notifications\EmailChangeNewAddressNotification;
use App\Notifications\EmailChangeOldAddressNotification;

final class SendEmailChangeNotifications
{
    public function handle(EmailChangeRequested $event): void
    {
        // newest request wins: kill any outstanding change links before staging a new one
        AuthLink::revokeOutstanding($event->user->id, 'confirm_email_change');

        $link = AuthLink::issue($event->user, 'confirm_email_change', minutes: (int) config('auth.links.verify_email_minutes'), email: $event->newEmail);

        $event->user->notify(new EmailChangeNewAddressNotification($link, $event->newEmail));
        $event->user->notify(new EmailChangeOldAddressNotification($event->newEmail));
    }
}
