<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\User;

final readonly class StopImpersonationAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $admin, int $tokenId): void
    {
        $token = $admin->ownsImpersonationToken($tokenId);

        $token->delete();

        $this->audit->log('impersonation_stop', $admin, null, ['token_id' => $tokenId]);
    }
}
