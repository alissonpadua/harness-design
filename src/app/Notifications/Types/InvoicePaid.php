<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class InvoicePaid implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'billing.invoice_paid';
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
        return 'Invoice paid — '.$data['amount'].' '.$data['currency'];
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
        return ['An invoice for '.$data['amount'].' '.$data['currency'].' was paid for '.$data['org_name'].'.', 'The PDF is available in the billing portal.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'View invoice', isset($data['url']) ? (string) $data['url'] : null);
    }
}
