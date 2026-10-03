<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Str;

/**
 * Single-use token pattern (raw shown once, DB keeps sha256). The plaintext
 * lives OUTSIDE $attributes so it can never reach an INSERT.
 */
trait HasHashedTokens
{
    private ?string $plainToken = null;

    public static function makeWithToken(): static
    {
        /** @var static $model */
        $model = new static; // @phpstan-ignore new.static (internal factory helper; consumers are final)
        $token = Str::random(48);
        $model->setAttribute('token_hash', hash('sha256', $token));
        $model->plainToken = $token;

        return $model;
    }

    public function token(): string
    {
        return (string) $this->plainToken;
    }

    /** Token is the capability: lookup ignores tenancy context by design. */
    public static function findByRawToken(string $token): ?static
    {
        $query = static::query();

        if (in_array(BelongsToOrganization::class, class_uses_recursive(static::class), true)) {
            $query->withoutGlobalScope(OrganizationScope::class);
        }

        return $query->where('token_hash', hash('sha256', $token))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && ! $this->isExpired();
    }
}
