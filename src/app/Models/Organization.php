<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgRole;
use App\Enums\OrgType;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OrgType $type
 * @property int $owner_id
 * @property string|null $logo_url
 * @property string|null $domain
 * @property OrgRole $default_member_role
 * @property bool $require_2fa
 * @property bool $invite_only
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'type', 'owner_id', 'logo_url', 'domain', 'default_member_role', 'require_2fa', 'invite_only'];

    protected function casts(): array
    {
        return [
            'type' => OrgType::class,
            'default_member_role' => OrgRole::class,
            'require_2fa' => 'boolean',
            'invite_only' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $org): void {
            if (! $org->slug) {
                $org->slug = self::uniqueSlug($org->name);
            }

            // mirror DB defaults onto the in-memory model so casts() are non-null post-create
            $org->default_member_role ??= OrgRole::Member;
            $org->require_2fa ??= false;
            $org->invite_only ??= true;
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $n = 1;

        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this, OrganizationMembership, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(OrganizationMembership::class)->withPivot(['id', 'role', 'status', 'invited_by', 'created_at'])->withTimestamps()->as('membership');
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<OrganizationInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(OrganizationInvite::class);
    }

    /** @return HasMany<OrganizationInviteLink, $this> */
    public function inviteLinks(): HasMany
    {
        return $this->hasMany(OrganizationInviteLink::class);
    }

    public static function resetCurrentPointer(self $org): void
    {
        User::query()->where('current_organization_id', $org->id)->update(['current_organization_id' => null]);
    }

    public function isPersonal(): bool
    {
        return $this->type === OrgType::Personal;
    }

    /** @return HasMany<BillingSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class);
    }

    public function subscription(): ?BillingSubscription
    {
        return $this->subscriptions()->latest('id')->first();
    }

    public function membershipFor(User $user): ?OrganizationMembership
    {
        return $this->memberships()->where('user_id', $user->id)->first();
    }

    /**
     * Resolve by numeric id or slug (spec 002 micro-decision #2).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIdentifier(Builder $query, string|int $key): Builder
    {
        return is_numeric($key)
            ? $query->whereKey((int) $key)
            : $query->where('slug', (string) $key);
    }
}
