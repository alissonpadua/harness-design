<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class SuspendUserAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $actor, User $target, string $reason): User
    {
        if ($target->isSuspended()) {
            throw ValidationException::withMessages(['user' => ['This account is already suspended.']]);
        }

        $target->forceFill(['suspended_at' => now(), 'suspend_reason' => $reason])->save();
        $target->tokens()->delete();

        $this->audit->log('user_suspend', $actor, $target, ['reason' => $reason]);

        return $target->refresh();
    }
}
