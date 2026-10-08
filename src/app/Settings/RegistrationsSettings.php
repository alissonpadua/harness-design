<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Registrations kill-switch (spec 006 S7, Q1=A minimal foundation).
 * Full typed-settings surface arrives with module 008.
 */
final class RegistrationsSettings extends Settings
{
    public bool $open = true;

    public static function group(): string
    {
        return 'registrations';
    }
}
