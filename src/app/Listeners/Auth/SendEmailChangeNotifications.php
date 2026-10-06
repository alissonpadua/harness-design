<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Auth\EmailChangeRequested;
use App\Models\AuthLink;
use App\Notifications\Support\NotifyUrls;
use App\Notifications\Types\EmailChange;

final readonly class SendEmailChangeNotifications
{
    public function __construct(private DispatchNotification $notify) {}

    public function handle(EmailChangeRequested $event): void
    {
        // newest request wins: kill any outstanding change links before staging a new one
        AuthLink::revokeOutstanding($event->user->id, 'confirm_email_change');

        $link = AuthLink::issue($event->user, 'confirm_email_change', minutes: (int) config('auth.links.verify_email_minutes'), email: $event->newEmail);

        $this->notify->email($event->newEmail, 'auth.email_change', [
            'variant' => EmailChange::NEW,
            'to' => $event->newEmail,
            'url' => NotifyUrls::confirmEmail($event->user, (string) $link->token),
        ]);

        $this->notify->user($event->user, 'auth.email_change', [
            'variant' => EmailChange::OLD,
            'from' => $event->user->email,
            'to' => $event->newEmail,
        ]);
    }
}
