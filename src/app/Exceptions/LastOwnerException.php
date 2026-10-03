<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** 409 — an organization must always keep exactly one owner. */
final class LastOwnerException extends RuntimeException {}
