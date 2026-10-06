<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** 502 — provider failures never leak vendor messages (spec 003 security). */
final class PaymentProviderException extends RuntimeException {}
