<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class RestoreOrganizationAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $actor, int $orgId): Organization
    {
        $org = Organization::withTrashed()->findOrFail($orgId);

        if (! $org->trashed()) {
            throw ValidationException::withMessages(['organization' => ['This organization is not deleted.']]);
        }

        $org->restore();

        $this->audit->log('org_restore', $actor, $org);

        return $org;
    }
}
