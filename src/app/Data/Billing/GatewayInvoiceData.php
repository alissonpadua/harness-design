<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class GatewayInvoiceData extends Data
{
    public function __construct(
        public readonly string $gatewayInvoiceId,
        public readonly string $status,
        public readonly int $amountDue,
        public readonly string $currency,
        public readonly ?string $hostedUrl,
        public readonly ?string $paidAt,
        public readonly ?string $dueAt,
    ) {}
}
