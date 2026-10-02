<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetPasswordNotification extends Notification
{
    public function __construct(
        public readonly string $token,
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
            ->subject('Reset your password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->line("Reset token: {$this->token}")
            ->line('This token expires in '.config('auth.passwords.users.expire').' minutes and can be used once. If you did not request a reset, no further action is required.');
    }
}
