<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * The ONLY sanctioned writer of explicit security audit rows (arch-tested).
 * Append-only: nothing in the app ever updates or deletes activity_log rows.
 */
final class AuditSecurityEvent
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, ?Model $causer = null, ?Model $subject = null, array $properties = []): void
    {
        if (! in_array($event, (array) config('audit.events'), true)) {
            throw new \InvalidArgumentException("Unregistered audit event [{$event}].");
        }

        $logger = $this->logger->inLog('audit');

        if ($subject !== null) {
            $logger = $logger->performedOn($subject);
        }

        $logger
            ->causedBy($causer)
            ->withProperties($properties)
            ->event($event)
            ->log($event);
    }
}
