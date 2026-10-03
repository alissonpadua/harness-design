<?php

declare(strict_types=1);

namespace App\Events\Org;

use App\Models\Organization;
use App\Models\OrganizationInvite;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MemberInvited
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Organization $organization,
        public readonly OrganizationInvite $invite,
    ) {}
}
