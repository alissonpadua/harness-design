<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * THE wrapper notification (spec 004): one concrete class carrying a
 * catalog type + payload. Channels = mail (preference-gated; locked always)
 * + database (persist) + broadcast (live Echo). Failures land in
 * failed_notifications for the sweep/manual retry (S5).
 */
final class CatalogDelivery extends Notification
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $channelsOverride  force subset (email-only recipients)
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
        public readonly ?array $channelsOverride = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($this->channelsOverride !== null) {
            return $this->channelsOverride;
        }

        $channels = ['database', 'broadcast'];

        if ($this->emailEnabledFor($notifiable)) {
            array_unshift($channels, 'mail');
        }

        return $channels;
    }

    private function emailEnabledFor(object $notifiable): bool
    {
        $entry = app(NotificationCatalog::class)->get($this->type);

        if ($entry->locked()) {
            return true;
        }

        if (! $notifiable instanceof User) {
            return $entry->emailDefault();
        }

        $pref = NotificationPreference::query()
            ->where('user_id', $notifiable->id)
            ->where('type', $this->type)
            ->first();

        return $pref->email_enabled ?? $entry->emailDefault();
    }

    public function toMail(object $notifiable): Mailable
    {
        $mail = app(NotificationCatalog::class)->get($this->type)->mailable($this->data);
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('mail', $this)
            : null;

        if (is_string($route) && $route !== '') {
            $mail = $mail->to($route);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $entry = app(NotificationCatalog::class)->get($this->type);

        return [
            'type' => $this->type,
            'title' => $entry->title($this->data),
            'body' => $entry->body($this->data),
            'data' => $this->data,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'notification.'.$this->type;
    }
}
