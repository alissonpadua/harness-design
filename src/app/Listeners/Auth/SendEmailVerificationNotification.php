<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Events\Auth\EmailVerificationRequested;
use App\Events\Auth\UserRegistered;
use App\Models\AuthLink;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;

/**
 * Owns auth-link creation + mail dispatch for both register and resend flows,
 * so no Action touches the mailer (arch rule + spec 001 AC-001.1/.2).
 */
final class SendEmailVerificationNotification
{
    public function onRegistered(UserRegistered $event): void
    {
        $this->send($event->user);
    }

    public function onRequested(EmailVerificationRequested $event): void
    {
        $this->send($event->user);
    }

    private function send(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $link = AuthLink::issue($user, 'verify_email', minutes: (int) config('auth.links.verify_email_minutes'));

        $user->notify(new EmailVerificationNotification($link));
    }
}
