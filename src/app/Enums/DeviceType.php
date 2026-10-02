<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceType: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Desktop = 'desktop';
    case Cli = 'cli';
}
