<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Illuminate\Validation\ValidationException;

final readonly class UpdatePreferences
{
    public function __construct(private NotificationCatalog $catalog) {}

    /**
     * @param  array<string, array{email?: bool}>  $preferences
     */
    public function handle(User $user, array $preferences): void
    {
        foreach ($preferences as $type => $settings) {
            if (! $this->catalog->has((string) $type)) {
                throw ValidationException::withMessages([
                    "notifications.{$type}" => ['Unknown notification type.'],
                ]);
            }

            if ($this->catalog->get((string) $type)->locked()) {
                throw ValidationException::withMessages([
                    "notifications.{$type}" => ['This notification type cannot be changed.'],
                ]);
            }

            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'type' => (string) $type],
                ['email_enabled' => (bool) ($settings['email'] ?? true)]
            );
        }
    }
}
