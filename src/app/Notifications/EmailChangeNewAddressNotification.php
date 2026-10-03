<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuthLink;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangeNewAddressNotification extends Notification
{
    public function __construct(
        public readonly AuthLink $link,
        public readonly string $newEmail,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function routeNotificationForMail(object $notifiable): string
    {
        return $this->newEmail; // deliver to the NEW address — that is the proof of control
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $user */
        $user = $notifiable;
        $url = rtrim((string) config('app.url'), '/')."/api/v1/auth/confirm-email/{$user->id}/{$this->link->token}";

        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->line('We received a request to change your account email to this address.')
            ->action('Confirm new email', $url)
            ->line('The change applies only after you confirm. If this was not you, ignore this email — your current address stays unchanged.');
    }
}
