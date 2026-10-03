<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrganizationInvite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrgInviteNotification extends Notification
{
    public function __construct(
        public readonly OrganizationInvite $invite,
        public readonly string $organizationName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orgName = $this->organizationName;

        return (new MailMessage)
            ->subject("You've been invited to join {$orgName}")
            ->line("You have been invited to join {$orgName} as {$this->invite->role->value}.")
            ->line("Accept with token: {$this->invite->token()}")
            ->line('Send it to POST /api/v1/invites/accept, or open the app invite screen.')
            ->line('This invitation expires in '.config('tenancy.invites.ttl_days').' days and can be used once.');
    }
}
