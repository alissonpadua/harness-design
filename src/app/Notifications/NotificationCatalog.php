<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Contracts\CatalogNotification;
use InvalidArgumentException;

/**
 * Auto-discovering registry: every App\Notifications\Types\* class is a
 * catalog entry — new types surface in the preferences matrix with zero
 * further wiring (feature_list 004 S1).
 */
final class NotificationCatalog
{
    /** @var array<string, CatalogNotification>|null */
    private ?array $types = null;

    /**
     * @return array<string, CatalogNotification>
     */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $found = [];

        foreach (glob(app_path('Notifications/Types/*.php')) ?: [] as $file) {
            $class = 'App\\Notifications\\Types\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $instance = app($class);

            if ($instance instanceof CatalogNotification) {
                $found[$instance->type()] = $instance;
            }
        }

        ksort($found);

        return $this->types = $found;
    }

    public function get(string $type): CatalogNotification
    {
        return $this->all()[$type]
            ?? throw new InvalidArgumentException("Unknown notification type [{$type}].");
    }

    public function has(string $type): bool
    {
        return isset($this->all()[$type]);
    }
}
