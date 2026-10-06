<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Actions\Notifications\DispatchNotification;
use App\Events\Auth\EmailVerificationRequested;
use App\Events\Auth\UserRegistered;
use App\Models\AuthLink;
use App\Models\User;
use App\Notifications\Support\NotifyUrls;

/**
 * Owns auth-link creation + catalog dispatch for both register and resend
 * flows, so no Action touches the mailer (arch rule + spec 001 AC-001.1/.2,
 * catalog migration per spec 004 AC-004.10).
 */
final readonly class SendEmailVerificationNotification
{
    public function __construct(private DispatchNotification $notify) {}

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

        $this->notify->user($user, 'auth.email_verification', [
            'url' => NotifyUrls::verifyEmail($user, (string) $link->token),
            'expires_at' => (string) $link->expires_at,
        ]);
    }
}
