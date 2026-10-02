<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * Output DTO for GET /api/v1/ping — proves the Data→OpenAPI schema pipeline
 * (Scramble infers this natively; JsonResource-over-array emitted JR001).
 */
final class PongData extends Data
{
    public function __construct(
        public readonly bool $pong,
    ) {}
}
