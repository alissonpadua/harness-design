<?php

declare(strict_types=1);

namespace App\Events\Auth;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Emitted by self-service change (T8) and completed reset (T4). */
final class PasswordChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly User $user) {}
}
