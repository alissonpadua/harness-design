<?php

declare(strict_types=1);

namespace App\Notifications\Contracts;

use Illuminate\Mail\Mailable;

/**
 * One class per notification type in the catalog (spec 004, feature-scope M4).
 * Types are data-shaping + template classes; delivery/channels/preferences
 * are owned centrally by CatalogDelivery + NotificationCatalog.
 */
interface CatalogNotification
{
    public function type(): string;

    public function locked(): bool;

    public function emailDefault(): bool;

    /**
     * @param  array<string, mixed>  $data
     */
    public function title(array $data): string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function body(array $data): string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function mailable(array $data): Mailable;
}
