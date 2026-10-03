<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** 403 — invitation token invalid/expired/consumed/mismatched (uniform). */
final class OrgTokenException extends RuntimeException
{
    public const MESSAGE = 'This invitation link is no longer valid.';
}
