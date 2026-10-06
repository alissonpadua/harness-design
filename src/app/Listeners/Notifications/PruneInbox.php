<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Keep only the newest N persisted notifications per user (spec 004 AC-004.7).
 */
final class PruneInbox
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        $keep = max(1, (int) config('notifications.inbox_keep'));

        $keepIds = DatabaseNotification::query()
            ->where('notifiable_type', $event->notifiable::class)
            ->where('notifiable_id', $event->notifiable->getKey())
            ->latest('created_at')
            ->limit($keep)
            ->pluck('id');

        DatabaseNotification::query()
            ->where('notifiable_type', $event->notifiable::class)
            ->where('notifiable_id', $event->notifiable->getKey())
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
