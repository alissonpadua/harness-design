<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Plan\PlanEntitlementsData;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property string $code
 * @property string $name
 * @property int $trial_days
 * @property bool $active
 * @property PlanEntitlementsData|array<string, mixed> $entitlements
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'trial_days', 'active', 'entitlements'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'trial_days' => 'integer',
        ];
    }

    /**
     * Strict typed JSON accessor: only the whitelisted entitlement shape round-trips.
     *
     * @return Attribute<PlanEntitlementsData, PlanEntitlementsData|array<string, mixed>>
     */
    protected function entitlements(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): PlanEntitlementsData => PlanEntitlementsData::from(json_decode((string) $value, true) ?? []),
            set: fn (mixed $value): string => json_encode(
                ($value instanceof PlanEntitlementsData ? $value : PlanEntitlementsData::from($this->normalizeEntitlements((array) $value)))->toArray(),
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeEntitlements(array $raw): array
    {
        $known = ['max_teams', 'max_members_per_org', 'webhooks', 'audit_retention_days', 'api_rate_limit_per_min'];
        $unknown = array_diff(array_keys($raw), $known);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown entitlement keys: '.implode(', ', $unknown));
        }

        $missing = array_diff($known, array_keys($raw));

        if ($missing !== []) {
            throw new InvalidArgumentException('Missing entitlement keys: '.implode(', ', $missing));
        }

        return $raw;
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function price(string $currency, string $interval): ?PlanPrice
    {
        return $this->prices
            ->first(fn (PlanPrice $p): bool => $p->currency === strtoupper($currency) && $p->interval->value === $interval);
    }

    public function isFree(): bool
    {
        return $this->code === 'free';
    }
}
