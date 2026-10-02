<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** AC-001.3 login-plane gate for accounts with an unverified email. */
final class EmailNotVerifiedException extends RuntimeException {}
