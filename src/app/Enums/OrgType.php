<?php

declare(strict_types=1);

namespace App\Enums;

enum OrgType: string
{
    case Personal = 'personal';
    case Team = 'team';
}
