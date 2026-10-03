<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangeOldAddressNotification extends Notification
{
    public function __construct(
        public readonly string $newEmail,
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
        return (new MailMessage)
            ->subject('Your email address is being changed')
            ->line("Your account email is about to change to {$this->newEmail}.")
            ->line('You will lose access to account notifications at this address once confirmed.')
            ->line('If you did not request this, change your password immediately — confirming the new address requires access to it.');
    }
}
