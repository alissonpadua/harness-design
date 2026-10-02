<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\AuthLinkException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-use signed links (verify_email | magic_link | confirm_email_change).
 * The raw token exists only inside issue()'s return value — DB keeps sha256.
 *
 * @property string $type
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $email
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property-read string|null $token transient plaintext set by issue(), never persisted
 */
class AuthLink extends Model
{
    protected $fillable = ['type', 'user_id', 'token_hash', 'email', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /**
     * issue() attaches the plaintext token as a transient attribute; read it
     * via `$link->token`. Only the sha256 (`token_hash` column) is persisted.
     */
    public static function issue(User $user, string $type, int $minutes = 60, ?string $email = null): self
    {
        $token = bin2hex(random_bytes(32));

        $link = self::create([
            'type' => $type,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'email' => $email,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $link->setAttribute('token', $token);

        return $link;
    }

    public static function consume(User $user, string $type, string $token): self
    {
        /** @var self|null $link */
        $link = self::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($link === null) {
            throw new AuthLinkException;
        }

        $link->forceFill(['used_at' => now()])->save();

        return $link;
    }
}
