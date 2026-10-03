<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $provider
 * @property string $provider_id
 * @property string|null $provider_email
 * @property bool $provider_email_verified
 */
class OauthAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_id', 'provider_email', 'provider_email_verified'];

    protected function casts(): array
    {
        return ['provider_email_verified' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
