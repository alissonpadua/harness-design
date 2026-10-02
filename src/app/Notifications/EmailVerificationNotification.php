<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuthLink;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Not a readonly class: Notification base is mutable framework state.
 */
final class EmailVerificationNotification extends Notification
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
        /** @var User $user */
        $user = $notifiable;
        $url = rtrim((string) config('app.url'), '/')."/api/v1/auth/verify-email/{$user->id}/{$this->link->token}";

        return (new MailMessage)
            ->subject('Verify your email address')
            ->line('Thanks for signing up! Click the button below to verify your email address.')
            ->action('Verify email', $url)
            ->line('This link expires in '.config('auth.links.verify_email_minutes').' minutes and can be used once.');
    }
}
