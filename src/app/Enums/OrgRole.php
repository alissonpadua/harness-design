<?php

declare(strict_types=1);

namespace App\Enums;

enum OrgRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';
}
