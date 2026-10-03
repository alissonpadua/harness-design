<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** 402 — entitlement ceiling reached (spec 002 seam, spec 003 plans). */
final class SubscriptionRequiredException extends RuntimeException {}
