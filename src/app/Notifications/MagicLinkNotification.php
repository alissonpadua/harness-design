<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuthLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MagicLinkNotification extends Notification
{
    public function __construct(
        public readonly AuthLink $link,
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
            ->subject('Your sign-in link')
            ->line('Use the token below to sign in — paste it into the app or send it to /api/v1/auth/magic-link/consume.')
            ->line("Sign-in token: {$this->link->token}")
            ->line('This link expires in '.config('auth.links.magic_link_minutes').' minutes and can be used once. It also verifies your email address.');
    }
}
