<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class IngestResultData extends Data
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $outcome, // processed | duplicate | ignored | failed
    ) {}
}
