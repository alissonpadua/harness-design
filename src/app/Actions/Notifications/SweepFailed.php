<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\FailedNotification;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Redeliver failed rows (Q3): sweeper respects attempts cap + backoff;
 * manual command can force parked rows.
 */
final readonly class SweepFailed
{
    /**
     * @return array{processed: int, succeeded: int, failed: int}
     */
    public function handle(bool $manualAll = false, ?int $onlyId = null): array
    {
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        $max = (int) config('notifications.retry.max_attempts');

        FailedNotification::query()
            ->whereNull('resolved_at')
            ->when($onlyId !== null, fn ($q) => $q->whereKey($onlyId))
            ->when($onlyId === null && ! $manualAll, fn ($q) => $q
                ->where('attempts', '<', $max)
                ->where(fn ($w) => $w->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now())))
            ->each(function (FailedNotification $row) use (&$stats): void {
                $stats['processed']++;

                try {
                    Notification::sendNow($this->recipientFor($row), new CatalogDelivery(
                        $row->type,
                        $row->payload,
                        $row->recipient_type === 'email' ? ['mail'] : null,
                    ));

                    $row->forceFill(['resolved_at' => now()])->save();
                    $stats['succeeded']++;
                } catch (Throwable $e) {
                    $row->markAttempt($e->getMessage());
                    $stats['failed']++;
                }
            });

        return $stats;
    }

    private function recipientFor(FailedNotification $row): User|AnonymousNotifiable
    {
        if ($row->recipient_type === 'user' && $row->recipient_id !== null) {
            $user = User::query()->find($row->recipient_id);

            if ($user !== null) {
                return $user;
            }
        }

        return (new AnonymousNotifiable)->route('mail', (string) $row->recipient_email);
    }
}
