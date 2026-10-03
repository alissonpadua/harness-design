<?php

declare(strict_types=1);

namespace App\Data\Org;

use Spatie\LaravelData\Data;

final class MemberListData extends Data
{
    /**
     * @param  array<int, MemberData>  $members
     */
    public function __construct(
        public readonly array $members,
    ) {}
}
