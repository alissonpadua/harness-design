<?php

declare(strict_types=1);

namespace App\Data\Org;

use Spatie\LaravelData\Data;

final class OrgListData extends Data
{
    /**
     * @param  array<int, OrganizationData>  $orgs
     */
    public function __construct(
        public readonly array $orgs,
    ) {}
}
