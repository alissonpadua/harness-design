<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $type
 * @property bool $email_enabled
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'email_enabled'];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
        ];
    }
}
