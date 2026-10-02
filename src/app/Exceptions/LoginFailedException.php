<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Shared denial for wrong password, unknown email and soft-deleted/suspended
 * accounts (AC-001.6/.7) — the renderer owns the message, never this class.
 */
final class LoginFailedException extends RuntimeException {}
