<?php

declare(strict_types=1);

namespace App\Notifications\Types\Concerns;

use App\Notifications\Mailables\CatalogMailable;

trait BuildsCatalogMail
{
    /**
     * @param  array<int, string>  $lines
     */
    private function mail(string $subject, array $lines, ?string $actionText = null, ?string $actionUrl = null): CatalogMailable
    {
        return new CatalogMailable(
            subjectText: $subject,
            heading: $subject,
            lines: $lines,
            accent: (string) config('notifications.theme.accent'),
            appName: (string) config('notifications.theme.from_name'),
            actionText: $actionText,
            actionUrl: $actionUrl,
        );
    }
}
