<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Single-use signed-link failure (expired / tampered / consumed / wrong scope).
 * Always the same generic 403 message — never reveals which condition occurred.
 */
final class AuthLinkException extends HttpException
{
    public const GENERIC_MESSAGE = 'This verification link is no longer valid.';

    public function __construct()
    {
        parent::__construct(403, self::GENERIC_MESSAGE);
    }
}
