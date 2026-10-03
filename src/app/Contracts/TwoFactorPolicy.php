<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

/**
 * Whether a user MUST have 2FA (AC-001.19). Spec 002 rebinds this per-org
 * (org security settings); the default never enforces.
 */
interface TwoFactorPolicy
{
    public function requires(User $user): bool;
}
