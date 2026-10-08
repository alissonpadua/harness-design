<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Notifications\DispatchNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use LaravelWebauthn\Models\WebauthnKey;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspend_reason
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property-read OrganizationMembership|null $membership
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password'];

    /** @return MorphMany<PersonalAccessToken, $this> */
    public function impersonationTokens(): MorphMany
    {
        return $this->tokens()->whereNotNull('impersonator_id');
    }

    /** Tokens THROUGH which this admin impersonates others (tokenable = target).
     *
     * @return Builder<PersonalAccessToken>
     */
    public function activeImpersonations(): Builder
    {
        return PersonalAccessToken::query()->where('impersonator_id', $this->id);
    }

    public function ownsImpersonationToken(int $tokenId): PersonalAccessToken
    {
        return $this->activeImpersonations()->whereKey($tokenId)->firstOrFail();
    }

    /** @return array{impersonator_id: int, impersonator_name: string|null, started_at: string|null}|null */
    public function currentImpersonation(): ?array
    {
        if (! $this->isImpersonating()) {
            return null;
        }

        /** @var PersonalAccessToken $token */
        $token = $this->currentAccessToken();
        $impersonatorId = (int) $token->getAttribute('impersonator_id');

        return [
            'impersonator_id' => $impersonatorId,
            'impersonator_name' => self::query()->find($impersonatorId)?->name,
            'started_at' => $token->created_at?->toIso8601String(),
        ];
    }

    public function hasSecondFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null || $this->passkeys()->exists();
    }

    public function isImpersonating(mixed $token = null): bool
    {
        $token ??= $this->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return (int) $token->getAttribute('impersonator_id') > 0;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    /** @return BelongsToMany<Organization, $this, OrganizationMembership, 'membership'> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->using(OrganizationMembership::class)->withPivot(['id', 'role', 'status', 'invited_by', 'created_at'])->withTimestamps()->as('membership');
    }

    public function routeNotificationForBroadcast(mixed $notification = null): string
    {
        return 'user.'.$this->id;
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /** @return HasMany<WebauthnKey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(WebauthnKey::class);
    }

    /**
     * Own the reset mail (spec 001 AC-001.10) instead of the framework default.
     */
    public function sendPasswordResetNotification($token): void
    {
        app(DispatchNotification::class)->user($this, 'auth.password_reset', ['token' => $token]);
    }
}
