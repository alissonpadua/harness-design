<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Models\FailedNotification;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The ONLY sanctioned notify() entrypoint (arch rule): queueing + failure
 * evidence in one place. dispatch() from Listeners/Actions; never call
 * ->notify() directly.
 */
final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  User|AnonymousNotifiable  $recipient
     */
    public function __construct(
        public readonly object $recipient,
        public readonly CatalogDelivery $delivery,
    ) {}

    public function handle(): void
    {
        try {
            Notification::sendNow($this->recipient, $this->delivery);
        } catch (Throwable $e) {
            $this->record($e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->record($exception->getMessage());
    }

    private function record(string $error): void
    {
        $recipient = $this->recipient;
        $isUser = $recipient instanceof User;

        FailedNotification::create([
            'type' => $this->delivery->type,
            'recipient_type' => $isUser ? 'user' : 'email',
            'recipient_id' => $isUser ? $recipient->id : null,
            'recipient_email' => $isUser ? $recipient->email : $recipient->routeNotificationFor('mail'),
            'payload' => $this->delivery->data,
            'error' => $error,
            'failed_at' => now(),
        ]);
    }
}
