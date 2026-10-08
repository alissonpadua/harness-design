<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class RestoreUserAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $actor, int $userId): User
    {
        $target = User::withTrashed()->findOrFail($userId);

        if (! $target->trashed()) {
            throw ValidationException::withMessages(['user' => ['This account is not deleted.']]);
        }

        // restore is orthogonal to suspension: suspended_at survives (AC-005.4)
        $target->restore();

        $this->audit->log('user_restore', $actor, $target);

        return $target;
    }
}
