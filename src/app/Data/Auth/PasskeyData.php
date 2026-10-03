<?php

declare(strict_types=1);

namespace App\Data\Auth;

use LaravelWebauthn\Models\WebauthnKey;
use Spatie\LaravelData\Data;

final class PasskeyData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $created_at,
    ) {}

    public static function make(WebauthnKey $key): self
    {
        return new self(
            id: (int) $key->getKey(),
            name: (string) $key->getAttribute('name'),
            created_at: $key->created_at?->toIso8601String(),
        );
    }
}
