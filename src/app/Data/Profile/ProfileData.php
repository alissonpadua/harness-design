<?php

declare(strict_types=1);

namespace App\Data\Profile;

use App\Models\User;
use Spatie\LaravelData\Data;

final class ProfileData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $email_verified_at,
        public readonly string $locale,
        public readonly string $timezone,
    ) {}

    public static function make(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            email_verified_at: $user->email_verified_at?->toIso8601String(),
            locale: $user->locale,
            timezone: $user->timezone,
        );
    }
}
