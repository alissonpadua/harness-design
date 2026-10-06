<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $type
 * @property string $recipient_type
 * @property int|null $recipient_id
 * @property string|null $recipient_email
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property string $error
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $resolved_at
 */
class FailedNotification extends Model
{
    protected $fillable = [
        'type', 'recipient_type', 'recipient_id', 'recipient_email',
        'payload', 'attempts', 'error', 'next_retry_at', 'resolved_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'resolved_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function markAttempt(string $error): void
    {
        $this->attempts++;
        $this->error = $error;

        if ($this->attempts >= (int) config('notifications.retry.max_attempts')) {
            $this->next_retry_at = null; // parked — manual --all only
        } else {
            $this->next_retry_at = now()->addMinutes(
                (int) config('notifications.retry.backoff_minutes') * (2 ** $this->attempts)
            );
        }

        $this->save();
    }

    public function isRedeliverable(): bool
    {
        return $this->resolved_at === null
            && ($this->next_retry_at === null ? $this->attempts === 0 : $this->next_retry_at->isPast());
    }
}
