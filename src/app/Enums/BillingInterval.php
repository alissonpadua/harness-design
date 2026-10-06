<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function stripeInterval(): string
    {
        return match ($this) {
            self::Monthly => 'month',
            self::Annual => 'year',
        };
    }
}
