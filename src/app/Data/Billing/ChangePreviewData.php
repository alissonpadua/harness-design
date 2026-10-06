<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class ChangePreviewData extends Data
{
    /**
     * @param  array<int, ProrationLineData>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $totalDue,
        public readonly string $currency,
    ) {}
}
