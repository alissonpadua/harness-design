<?php

declare(strict_types=1);

namespace App\Events\Org;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MemberRoleChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Organization $organization,
        public readonly User $member,
        public readonly OrgRole $newRole,
    ) {}
}
