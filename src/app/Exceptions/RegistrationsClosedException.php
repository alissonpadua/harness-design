<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Kill-switch denial (spec 006 AC-006.10): register + oauth-first-login lanes
 * while RegistrationsSettings::$open is false.
 */
final class RegistrationsClosedException extends \Exception {}
