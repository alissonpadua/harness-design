<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class UnsuspendUserAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $actor, User $target): User
    {
        if (! $target->isSuspended()) {
            throw ValidationException::withMessages(['user' => ['This account is not suspended.']]);
        }

        $target->forceFill(['suspended_at' => null, 'suspend_reason' => null])->save();

        $this->audit->log('user_unsuspend', $actor, $target);

        return $target->refresh();
    }
}
