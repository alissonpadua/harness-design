<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\User;

final class NotifyUrls
{
    public static function api(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$path;
    }

    public static function verifyEmail(User $user, string $token): string
    {
        return self::api("api/v1/auth/verify-email/{$user->id}/{$token}");
    }

    public static function confirmEmail(User $user, string $token): string
    {
        return self::api("api/v1/auth/confirm-email/{$user->id}/{$token}");
    }

    public static function invite(string $token): string
    {
        return self::api("accept-invite/{$token}");
    }
}
