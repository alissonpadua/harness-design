<?php

declare(strict_types=1);

namespace App\Events\Org;

use App\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrgDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Organization $organization) {}
}
