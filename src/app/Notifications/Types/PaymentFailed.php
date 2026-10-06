<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class PaymentFailed implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'billing.payment_failed';
    }

    public function locked(): bool
    {
        return false;
    }

    public function emailDefault(): bool
    {
        return true;
    }

    public function title(array $data): string
    {
        return 'Payment failed for '.$data['org_name'];
    }

    public function body(array $data): string
    {
        return implode(' ', $this->lines($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function lines(array $data): array
    {
        return ['We could not collect the latest payment for '.$data['org_name'].'.', 'The team keeps working during the grace window — update the payment method to avoid downgrading to Free.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'Fix payment', isset($data['portal_url']) ? (string) $data['portal_url'] : null);
    }
}
