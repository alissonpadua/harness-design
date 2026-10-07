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
        $safe = static fn (string $s): string => str_replace(
            ['\\', '[', ']', '(', ')', '`'],
            ['&#92;', '&#91;', '&#93;', '&#40;', '&#41;', '&#96;'],
            $s,
        );

        return new CatalogMailable(
            subjectText: $subject,
            heading: $safe($subject),
            lines: array_map($safe, $lines),
            accent: (string) config('notifications.theme.accent'),
            appName: (string) config('notifications.theme.from_name'),
            actionText: $actionText,
            actionUrl: $actionUrl,
        );
    }
}
